package main

/*
#include <stdlib.h>
*/
import "C"

import (
	"xlb/android/core/internal/proofbridge"
)

var androidProofBridgeManager = proofbridge.NewManager()

func startAndroidProofBridge(remoteURL, caBundle, localUsername, localPassword string) error {
	androidDeviceCertificateMu.Lock()
	if androidDeviceCertificate == nil {
		androidDeviceCertificateMu.Unlock()
		return proofbridge.ErrDeviceCertificateUnavailable
	}
	certificate := *androidDeviceCertificate
	androidDeviceCertificateMu.Unlock()

	return androidProofBridgeManager.Start(
		proofbridge.ListenAddress, remoteURL, caBundle, localUsername, localPassword, certificate)
}

func stopAndroidProofBridge() {
	androidProofBridgeManager.Stop()
}

func androidProofBridgeRunning() bool {
	return androidProofBridgeManager.IsRunning()
}

//export XlbStartAndroidProofBridge
func XlbStartAndroidProofBridge(remoteURL *C.char, remoteURLLength C.int,
	caBundle *C.char, caBundleLength C.int, localUsername *C.char, localUsernameLength C.int,
	localPassword *C.char, localPasswordLength C.int) *C.char {
	if remoteURL == nil || caBundle == nil || localUsername == nil || localPassword == nil {
		return C.CString("proof bridge configuration is required")
	}
	if remoteURLLength < 1 || remoteURLLength > 2048 || caBundleLength < 1 || caBundleLength > 64*1024 ||
		localUsernameLength < 1 || localUsernameLength > 255 || localPasswordLength < 1 || localPasswordLength > 255 {
		return C.CString("proof bridge configuration is invalid")
	}
	if err := startAndroidProofBridge(
		C.GoStringN(remoteURL, remoteURLLength), C.GoStringN(caBundle, caBundleLength),
		C.GoStringN(localUsername, localUsernameLength), C.GoStringN(localPassword, localPasswordLength)); err != nil {
		return C.CString(err.Error())
	}
	return nil
}

//export XlbStopAndroidProofBridge
func XlbStopAndroidProofBridge() {
	stopAndroidProofBridge()
}

//export XlbAndroidProofBridgeRunning
func XlbAndroidProofBridgeRunning() C.int {
	if androidProofBridgeRunning() {
		return 1
	}
	return 0
}
