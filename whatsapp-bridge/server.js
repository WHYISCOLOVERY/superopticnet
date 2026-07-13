import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import express from "express";
import cors from "cors";
import rateLimit from "express-rate-limit";
import qrcode from "qrcode";
import qrcodeTerminal from "qrcode-terminal";
import pkg from "whatsapp-web.js";

const { Client, LocalAuth } = pkg;
const execFileAsync = promisify(execFile);

const app = express();
const host = process.env.HOST || "127.0.0.1";
const port = Number.parseInt(process.env.PORT || "4001", 10);
const apiSecret = (process.env.API_SECRET_KEY || "").trim();
const pairingWorkerToken = (process.env.PAIRING_WORKER_TOKEN || apiSecret).trim();
const portalBaseUrl = (process.env.PORTAL_BASE_URL || "http://127.0.0.1/superopticnet").replace(/\/$/, "");
const pairingPollMs = Math.max(2000, Number.parseInt(process.env.PAIRING_POLL_MS || "5000", 10));
const pairingWorkerId = process.env.PAIRING_WORKER_ID || `${process.env.COMPUTERNAME || "superoptic"}-whatsapp`;
const origins = (process.env.ALLOWED_ORIGINS || "http://127.0.0.1,http://localhost")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

let qrCodeData = "";
let isReady = false;
let lastError = "";
let readyAt = "";
let lastQrAt = "";
const pairingSessions = new Map();

const browserCandidates = [
  process.env.PUPPETEER_EXECUTABLE_PATH,
  "C:\\Program Files\\BraveSoftware\\Brave-Browser\\Application\\brave.exe",
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
].filter(Boolean);
const browserExecutable = browserCandidates.find((candidate) => fs.existsSync(candidate));

const puppeteerOptions = {
  headless: true,
  protocolTimeout: Number.parseInt(process.env.PUPPETEER_PROTOCOL_TIMEOUT_MS || "120000", 10),
  timeout: Number.parseInt(process.env.PUPPETEER_LAUNCH_TIMEOUT_MS || "120000", 10),
  args: [
    "--no-sandbox",
    "--disable-setuid-sandbox",
    "--disable-dev-shm-usage",
    "--disable-accelerated-2d-canvas",
    "--no-first-run",
    "--no-zygote",
    "--disable-gpu",
    "--hide-scrollbars",
    "--disable-notifications",
    "--disable-background-timer-throttling",
    "--disable-backgrounding-occluded-windows",
    "--disable-breakpad",
    "--disable-component-extensions-with-background-pages",
    "--disable-extensions",
    "--disable-features=TranslateUI,BlinkGenPropertyTrees",
    "--disable-ipc-flooding-protection",
    "--disable-renderer-backgrounding",
    "--enable-features=NetworkService,NetworkServiceInProcess",
    "--force-color-profile=srgb",
    "--metrics-recording-only",
    "--mute-audio",
  ],
  userAgent: process.env.USER_AGENT || "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
  ...(browserExecutable ? { executablePath: browserExecutable } : {}),
};

app.use(express.json({ limit: "256kb" }));
app.use(cors({ origin: origins, credentials: false }));
app.use(rateLimit({
  windowMs: 1000,
  max: 10,
  standardHeaders: true,
  legacyHeaders: false,
  message: { ok: false, error: "rate_limited" },
}));

function requireApiKey(req, res, next) {
  if (apiSecret === "") {
    return res.status(503).json({ ok: false, error: "api_key_not_configured" });
  }

  const provided = String(req.headers["x-api-key"] || "");
  if (provided !== apiSecret) {
    return res.status(403).json({ ok: false, error: "forbidden" });
  }

  return next();
}

function normalizeWhatsappNumber(value) {
  let digits = String(value || "").replace(/\D/g, "");
  if (digits.startsWith("00")) {
    digits = digits.slice(2);
  }
  if (digits.length === 10 && digits.startsWith("0")) {
    digits = `994${digits.slice(1)}`;
  }
  if (digits.length === 9 && /^[457]\d{8}$/.test(digits)) {
    digits = `994${digits}`;
  }
  if (digits === "") {
    return "";
  }
  return `${digits}@c.us`;
}

function publicStatus() {
  return {
    ok: true,
    connected: isReady,
    status: isReady ? "connected" : (qrCodeData !== "" ? "qr" : "loading"),
    ready_at: readyAt,
    last_qr_at: lastQrAt,
    last_error: lastError,
  };
}

const client = new Client({
  authStrategy: new LocalAuth({ clientId: "superoptic-main" }),
  authTimeoutMs: Number.parseInt(process.env.WHATSAPP_AUTH_TIMEOUT_MS || "120000", 10),
  takeoverOnConflict: true,
  takeoverTimeoutMs: Number.parseInt(process.env.WHATSAPP_TAKEOVER_TIMEOUT_MS || "30000", 10),
  puppeteer: puppeteerOptions,
});

app.get("/health", (_req, res) => {
  res.json({ ok: true, service: "superoptic-whatsapp-bridge" });
});

app.get("/api/status", requireApiKey, (_req, res) => {
  res.json(publicStatus());
});

app.get("/api/qr", requireApiKey, async (_req, res) => {
  if (isReady) {
    return res.json({ ...publicStatus(), qr: "" });
  }
  if (qrCodeData === "") {
    return res.json({ ...publicStatus(), qr: "" });
  }

  try {
    const qr = await qrcode.toDataURL(qrCodeData);
    return res.json({ ...publicStatus(), qr });
  } catch (error) {
    lastError = error instanceof Error ? error.message : String(error);
    return res.status(500).json({ ok: false, error: lastError });
  }
});

app.post("/api/send", requireApiKey, async (req, res) => {
  const requestedSession = String(req.body?.session_key || "").trim();
  const paired = requestedSession !== "" ? pairingSessions.get(requestedSession) : null;
  const sendingClient = paired?.ready ? paired.client : client;
  if (sendingClient === client && !isReady) {
    return res.status(503).json({ ok: false, error: "whatsapp_not_ready", status: publicStatus() });
  }
  if (requestedSession !== "" && !paired?.ready) {
    return res.status(503).json({ ok: false, error: "pairing_session_not_ready", session_key: requestedSession });
  }

  const number = normalizeWhatsappNumber(req.body?.number || req.body?.to || "");
  const message = String(req.body?.message || "").trim();
  if (number === "" || message === "") {
    return res.status(400).json({ ok: false, error: "number_and_message_required" });
  }

  try {
    const result = await sendingClient.sendMessage(number, message);
    return res.json({
      ok: true,
      success: true,
      to: number,
      message_id: result?.id?._serialized || result?.id?.id || null,
    });
  } catch (error) {
    lastError = error instanceof Error ? error.message : String(error);
    return res.status(500).json({ ok: false, error: lastError });
  }
});

client.on("qr", (qr) => {
  qrCodeData = qr;
  isReady = false;
  lastQrAt = new Date().toISOString();
  lastError = "";
  qrcodeTerminal.generate(qr, { small: true });
  console.log("[whatsapp-bridge] QR generated. Open message center to scan.");
});

client.on("ready", () => {
  isReady = true;
  qrCodeData = "";
  readyAt = new Date().toISOString();
  lastError = "";
  console.log("[whatsapp-bridge] WhatsApp session is ready.");
});

client.on("auth_failure", (message) => {
  isReady = false;
  lastError = `auth_failure: ${message}`;
  console.error("[whatsapp-bridge]", lastError);
});

client.on("disconnected", (reason) => {
  isReady = false;
  lastError = `disconnected: ${reason}`;
  console.warn("[whatsapp-bridge]", lastError);
});

async function pairingApi(path, options = {}) {
  const response = await fetch(`${portalBaseUrl}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-WhatsApp-Monitor-Token": pairingWorkerToken,
      ...(options.headers || {}),
    },
    signal: AbortSignal.timeout(15000),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || payload.ok === false) {
    throw new Error(`pairing_api_${response.status}:${payload.error || "unknown"}`);
  }
  return payload;
}

async function updatePairing(sessionKey, status, extra = {}) {
  try {
    await pairingApi("/api/whatsapp-monitor/pairing-update.php", {
      method: "POST",
      body: JSON.stringify({ session_key: sessionKey, status, worker_id: pairingWorkerId, ...extra }),
    });
  } catch (error) {
    console.error(`[pairing-worker] ${sessionKey} status ${status} failed:`, error instanceof Error ? error.message : error);
  }
}

async function ingestPairingMessage(sessionKey, message) {
  try {
    const [chat, contact] = await Promise.all([
      message.getChat().catch(() => null),
      message.getContact().catch(() => null),
    ]);
    await pairingApi("/api/whatsapp-monitor/message-ingest.php", {
      method: "POST",
      body: JSON.stringify({
        session_key: sessionKey,
        message_id: message?.id?._serialized || message?.id?.id || "",
        chat_id: chat?.id?._serialized || (message.fromMe ? message.to : message.from) || "",
        from: message.from || "",
        to: message.to || "",
        from_me: Boolean(message.fromMe),
        contact_name: chat?.name || contact?.pushname || contact?.name || contact?.number || "",
        type: message.type || "chat",
        body: message.body || "",
        has_media: Boolean(message.hasMedia),
        timestamp: Number(message.timestamp || Math.floor(Date.now() / 1000)),
      }),
    });
  } catch (error) {
    console.error(`[pairing-worker] message ingest failed for ${sessionKey}:`, error instanceof Error ? error.message : error);
  }
}

async function syncRecentChats(sessionKey, pairingClient) {
  try {
    const chatLimit = Math.max(1, Math.min(100, Number.parseInt(process.env.PAIRING_SYNC_CHAT_LIMIT || "50", 10)));
    const messageLimit = Math.max(1, Math.min(100, Number.parseInt(process.env.PAIRING_SYNC_MESSAGE_LIMIT || "30", 10)));
    const chats = await pairingClient.getChats();
    for (const chat of chats.slice(0, chatLimit)) {
      const messages = await chat.fetchMessages({ limit: messageLimit });
      for (const message of messages) {
        await ingestPairingMessage(sessionKey, message);
      }
    }
    console.log(`[pairing-worker] Synced recent chats for ${sessionKey}`);
  } catch (error) {
    console.error(`[pairing-worker] chat sync failed for ${sessionKey}:`, error instanceof Error ? error.message : error);
  }
}

async function stopBrowsersForProfile(clientId) {
  if (process.platform !== "win32") return;
  const profilePath = path.resolve(".wwebjs_auth", `session-${clientId}`);
  const escaped = profilePath.replace(/'/g, "''");
  const command = `$needle='${escaped}'; Get-CimInstance Win32_Process -Filter \"Name='brave.exe' OR Name='chrome.exe' OR Name='msedge.exe'\" | Where-Object { $_.CommandLine -like \"*$needle*\" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`;
  await execFileAsync("powershell.exe", ["-NoProfile", "-NonInteractive", "-Command", command], { windowsHide: true, timeout: 15000 }).catch(() => null);
}

async function stopPairingSession(sessionKey) {
  const state = pairingSessions.get(sessionKey);
  if (!state) return;
  pairingSessions.delete(sessionKey);
  try {
    await state.client.destroy();
  } catch {
    // Browser may already be closed after an auth or launch failure.
  }
}

async function startPairingSession(item) {
  const sessionKey = String(item.session_key || "").trim();
  if (sessionKey === "" || pairingSessions.has(sessionKey)) return;

  const clientId = `pairing-${sessionKey.replace(/[^a-zA-Z0-9_-]/g, "-").slice(0, 80)}`;
  const pairingClient = new Client({
    authStrategy: new LocalAuth({ clientId }),
    authTimeoutMs: Number.parseInt(process.env.WHATSAPP_AUTH_TIMEOUT_MS || "120000", 10),
    takeoverOnConflict: true,
    takeoverTimeoutMs: Number.parseInt(process.env.WHATSAPP_TAKEOVER_TIMEOUT_MS || "30000", 10),
    puppeteer: puppeteerOptions,
  });
  const state = { client: pairingClient, ready: false, expiresAt: String(item.expires_at || ""), status: String(item.pairing_status || "requested") };
  pairingSessions.set(sessionKey, state);

  pairingClient.on("qr", async (qrPayload) => {
    state.status = "qr_ready";
    const qrImage = await qrcode.toDataURL(qrPayload, { errorCorrectionLevel: "M", margin: 2, width: 360 });
    await updatePairing(sessionKey, "qr_ready", { qr_payload: qrPayload, qr_image_data_uri: qrImage });
    console.log(`[pairing-worker] QR ready for ${item.phone_number || sessionKey}`);
  });
  pairingClient.on("authenticated", async () => {
    state.status = "scanned";
    await updatePairing(sessionKey, "scanned");
  });
  pairingClient.on("ready", async () => {
    state.ready = true;
    state.status = "connected";
    await updatePairing(sessionKey, "connected");
    console.log(`[pairing-worker] Connected ${item.phone_number || sessionKey}`);
    void syncRecentChats(sessionKey, pairingClient);
  });
  pairingClient.on("message_create", (message) => {
    void ingestPairingMessage(sessionKey, message);
  });
  pairingClient.on("auth_failure", async (message) => {
    state.status = "failed";
    await updatePairing(sessionKey, "failed", { failure_reason: `auth_failure: ${message}` });
    await stopPairingSession(sessionKey);
  });
  pairingClient.on("disconnected", async (reason) => {
    state.ready = false;
    state.status = "failed";
    await updatePairing(sessionKey, "failed", { failure_reason: `disconnected: ${reason}` });
    await stopPairingSession(sessionKey);
  });

  const initialize = async (allowProfileRecovery = true) => {
    try {
      await pairingClient.initialize();
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      if (state.ready || state.status === "connected") {
        console.warn(`[pairing-worker] Ignored late initialize error after ready for ${sessionKey}: ${message}`);
        return;
      }
      if (allowProfileRecovery && message.includes("browser is already running")) {
        console.warn(`[pairing-worker] Recovering locked browser profile for ${sessionKey}`);
        await stopBrowsersForProfile(clientId);
        await new Promise((resolve) => setTimeout(resolve, 1500));
        await initialize(false);
        return;
      }
      await updatePairing(sessionKey, "failed", { failure_reason: message.slice(0, 240) });
      await stopPairingSession(sessionKey);
    }
  };
  void initialize();
}

async function pollPairingQueue() {
  if (pairingWorkerToken === "") return;
  try {
    const payload = await pairingApi("/api/whatsapp-monitor/pairing-queue.php?limit=30");
    for (const item of payload.items || []) {
      await startPairingSession(item);
    }
    const now = Date.now();
    for (const [sessionKey, state] of pairingSessions.entries()) {
      const expiresAt = state.expiresAt ? Date.parse(state.expiresAt.replace(" ", "T")) : Number.NaN;
      if (!state.ready && Number.isFinite(expiresAt) && expiresAt <= now) {
        await updatePairing(sessionKey, "expired", { failure_reason: "qr_timeout" });
        await stopPairingSession(sessionKey);
      }
    }
  } catch (error) {
    console.error("[pairing-worker] queue failed:", error instanceof Error ? error.message : error);
  }
}

client.initialize().catch((error) => {
  lastError = error instanceof Error ? error.message : String(error);
  console.error("[whatsapp-bridge] initialize failed:", lastError);
});

app.listen(port, host, () => {
  console.log(`[whatsapp-bridge] listening on http://${host}:${port}`);
  console.log(`[whatsapp-bridge] browser=${browserExecutable || "bundled chromium"}`);
  if (pairingWorkerToken === "") {
    console.warn("[pairing-worker] disabled: PAIRING_WORKER_TOKEN or API_SECRET_KEY is required");
  } else {
    pollPairingQueue();
    setInterval(pollPairingQueue, pairingPollMs);
  }
});
