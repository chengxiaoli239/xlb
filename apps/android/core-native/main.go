package main

/*
#include <stdlib.h>
#include "callbacks.h"
*/
import "C"

import (
	"errors"
	"net/netip"
	"sync"
	"syscall"
	"unsafe"

	"github.com/metacubex/mihomo/component/dialer"
	"github.com/metacubex/mihomo/config"
	constant "github.com/metacubex/mihomo/constant"
	"github.com/metacubex/mihomo/hub"
	"github.com/metacubex/mihomo/hub/executor"
	"github.com/metacubex/mihomo/listener"
	listenerConfig "github.com/metacubex/mihomo/listener/config"
	"github.com/metacubex/mihomo/listener/sing_tun"
	"github.com/metacubex/mihomo/tunnel"
	"xlb/android/core/internal/debugprofile"
	"xlb/android/core/internal/profileerrors"
)

var coreLock sync.Mutex
var activeTun interface{ Close() error }
var protectCallback C.xlb_protect_callback
var loaded bool

func main() {}

//export XlbInit
func XlbInit(home *C.char, callback C.xlb_protect_callback) {
	coreLock.Lock()
	defer coreLock.Unlock()
	constant.SetHomeDir(C.GoString(home))
	protectCallback = callback
	dialer.DefaultSocketHook = func(_, _ string, conn syscall.RawConn) error {
		var protectErr error
		err := conn.Control(func(fd uintptr) {
			if C.xlb_call_protect(protectCallback, C.int(fd)) == 0 {
				protectErr = errors.New("VPN socket protection failed")
			}
		})
		if err != nil {
			return err
		}
		return protectErr
	}
}

//export XlbLoad
func XlbLoad(profile *C.char, length C.int) *C.char {
	coreLock.Lock()
	defer coreLock.Unlock()
	stopLocked()

	raw, err := config.UnmarshalRawConfig(C.GoBytes(unsafe.Pointer(profile), length))
	if err != nil {
		return C.CString("invalid profile")
	}
	minimal, err := debugprofile.FirstRouteProfile(raw)
	if err != nil {
		return C.CString(err.Error())
	}
	parsed, err := config.ParseRawConfig(minimal)
	if err != nil {
		return C.CString(profileerrors.ValidationMessage(err))
	}
	hub.ApplyConfig(parsed)
	if listener.GetPorts().MixedPort != 0 {
		stopLocked()
		return C.CString("isolated VPN profile exposed a local mixed inbound")
	}
	loaded = true
	if len(raw.Proxy) == 0 {
		name, _ := debugprofile.FirstRouteName(raw)
		provider := tunnel.Providers()[name]
		if provider == nil || len(provider.Proxies()) == 0 {
			stopLocked()
			return C.CString("proxy provider has no proxies")
		}
	}
	return nil
}

//export XlbLoadMemberBridgeProfile
func XlbLoadMemberBridgeProfile(profile *C.char, length C.int, username *C.char, usernameLength C.int,
	password *C.char, passwordLength C.int) *C.char {
	if profile == nil || username == nil || password == nil || length < 1 || length > 128*1024 ||
		usernameLength < 1 || usernameLength > 255 || passwordLength < 1 || passwordLength > 255 {
		return C.CString("invalid member bridge profile")
	}
	localUsername := C.GoStringN(username, usernameLength)
	localPassword := C.GoStringN(password, passwordLength)
	profileBytes := C.GoBytes(unsafe.Pointer(profile), length)
	withLocalCredentials, err := debugprofile.MemberBridgeProfile(profileBytes, localUsername, localPassword)
	if err != nil {
		return C.CString("invalid member bridge profile")
	}

	raw, err := config.UnmarshalRawConfig(withLocalCredentials)
	if err != nil {
		return C.CString("invalid member bridge profile")
	}
	parsed, err := config.ParseRawConfig(raw)
	if err != nil {
		return C.CString(profileerrors.ValidationMessage(err))
	}
	err = androidProofBridgeManager.WithActiveCredentials(localUsername, localPassword, func() error {
		coreLock.Lock()
		defer coreLock.Unlock()
		stopLocked()
		hub.ApplyConfig(parsed)
		if listener.GetPorts().MixedPort != 0 {
			stopLocked()
			return errors.New("member bridge profile exposed a local mixed inbound")
		}
		loaded = true
		return nil
	})
	if err != nil {
		return C.CString("member bridge authorization is unavailable")
	}
	return nil
}

//export XlbFirstRouteName
func XlbFirstRouteName(profile *C.char, length C.int) *C.char {
	raw, err := config.UnmarshalRawConfig(C.GoBytes(unsafe.Pointer(profile), length))
	if err != nil {
		return C.CString("")
	}
	name, err := debugprofile.FirstRouteName(raw)
	if err != nil {
		return C.CString("")
	}
	return C.CString(name)
}

//export XlbStartTun
func XlbStartTun(fd C.int) *C.char {
	coreLock.Lock()
	defer coreLock.Unlock()
	if !loaded || fd <= 0 {
		return C.CString("core is not ready")
	}
	if activeTun != nil {
		return C.CString("tunnel already running")
	}
	dupFD, err := syscall.Dup(int(fd))
	if err != nil {
		return C.CString("tunnel descriptor unavailable")
	}
	options := listenerConfig.Tun{
		Enable:              true,
		Device:              sing_tun.InterfaceName,
		Stack:               constant.TunMixed,
		DNSHijack:           []string{"0.0.0.0:53", "[::]:53"},
		AutoRoute:           false,
		AutoDetectInterface: false,
		Inet4Address:        []netip.Prefix{netip.MustParsePrefix("172.19.0.1/30")},
		Inet6Address:        []netip.Prefix{netip.MustParsePrefix("fdfe:dcba:9876::1/126")},
		MTU:                 9000,
		FileDescriptor:      dupFD,
	}
	device, err := sing_tun.New(options, tunnel.Tunnel)
	if err != nil {
		_ = syscall.Close(dupFD)
		return C.CString("tunnel startup failed")
	}
	activeTun = device
	return nil
}

//export XlbStop
func XlbStop() {
	stopAndroidProofBridge()
	coreLock.Lock()
	defer coreLock.Unlock()
	stopLocked()
}

func stopLocked() {
	if activeTun != nil {
		_ = activeTun.Close()
		activeTun = nil
	}
	if loaded {
		executor.Shutdown()
		loaded = false
	}
}

//export XlbFreeString
func XlbFreeString(value *C.char) {
	C.free(unsafe.Pointer(value))
}
