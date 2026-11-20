package com.xlb.vpn.data;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.fail;

public final class AccountProfileFetcherTest {
    private static final String PATH = "/subscribe/" + "a".repeat(43);
    private static final String PROFILE = "proxies:\n  - name: Test\n    type: socks5\n    server: node.example.test\n    port: 1080\n";
    private ServerSocket server;
    private String baseUrl;

    @Before
    public void startServer() throws IOException {
        server = new ServerSocket(0, 1, InetAddress.getByName("127.0.0.1"));
        baseUrl = "http://127.0.0.1:" + server.getLocalPort();
    }

    @After
    public void stopServer() throws IOException {
        server.close();
    }

    @Test
    public void downloadsSameOriginPublishedProfile() throws Exception {
        respond("200 OK", "production", PROFILE, null);

        assertEquals(PROFILE, AccountProfileFetcher.fetch(baseUrl, PATH));
    }

    @Test
    public void rejectsLocalPlaceholderAndForeignCredentialPaths() throws Exception {
        respond("200 OK", "local-test", PROFILE, null);

        expectUnavailable(PATH);
        expectUnavailable("https://foreign.example.test" + PATH);
        expectUnavailable("/subscribe/../admin");
    }

    @Test
    public void doesNotFollowRedirectsOrAcceptMissingRoutes() throws Exception {
        respond("302 Found", null, "", "https://foreign.example.test/profile");
        expectUnavailable(PATH);
    }

    private void respond(String status, String scope, String body, String location) {
        Thread responder = new Thread(() -> {
            try (Socket socket = server.accept()) {
                BufferedReader reader = new BufferedReader(new InputStreamReader(socket.getInputStream(), StandardCharsets.US_ASCII));
                while (true) {
                    String line = reader.readLine();
                    if (line == null || line.isEmpty()) break;
                }
                byte[] payload = body.getBytes(StandardCharsets.UTF_8);
                String headers = "HTTP/1.1 " + status + "\r\nContent-Length: " + payload.length + "\r\n"
                        + "Connection: close\r\n"
                        + (scope == null ? "" : "X-XLB-Profile-Scope: " + scope + "\r\n")
                        + (location == null ? "" : "Location: " + location + "\r\n") + "\r\n";
                socket.getOutputStream().write(headers.getBytes(StandardCharsets.US_ASCII));
                socket.getOutputStream().write(payload);
                socket.getOutputStream().flush();
            } catch (IOException ignored) {
                // The assertion on the client side reports a failed response.
            }
        });
        responder.setDaemon(true);
        responder.start();
    }

    private void expectUnavailable(String path) throws Exception {
        try {
            AccountProfileFetcher.fetch(baseUrl, path);
            fail("Expected the account profile to be unavailable");
        } catch (IOException exception) {
            if (exception.getMessage().contains("foreign.example.test")) fail("Credential URL leaked");
        }
    }
}
