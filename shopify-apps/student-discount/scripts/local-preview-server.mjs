import {createReadStream} from "node:fs";
import {createServer} from "node:http";
import {extname, join} from "node:path";

const host = "127.0.0.1";
const previewPort = Number(process.env.PREVIEW_PORT || 9293);
const apiPort = Number(process.env.PREVIEW_API_PORT || 3000);
const assetsDirectory = join(process.cwd(), "extensions/student-discount-block/assets");

const html = String.raw`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Deco student discount local preview</title>
  <link rel="stylesheet" href="/assets/student-discount.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #111; background: #f3f3f3; font-family: Arial, Helvetica, sans-serif; }
    .preview-header { padding: 22px 5vw; color: #fff; background: #050505; text-align: center; font-weight: 700; }
    .preview-product { display: grid; grid-template-columns: minmax(280px, 1fr) minmax(280px, 0.9fr); gap: 48px; width: min(1180px, calc(100% - 40px)); margin: 48px auto; padding: 48px; background: #fff; }
    .preview-image { min-height: 520px; background: linear-gradient(140deg, #ececec, #cfcfcf); display: grid; place-items: center; color: #555; font-size: 22px; }
    .preview-info h1 { margin: 0 0 12px; font-size: clamp(32px, 5vw, 64px); }
    .preview-info p { font-size: 18px; line-height: 1.6; }
    .preview-note { margin-top: 24px; color: #666; font-size: 14px; }
    @media (max-width: 760px) {
      .preview-product { grid-template-columns: 1fr; gap: 24px; margin: 0; padding: 24px 16px 48px; width: 100%; }
      .preview-image { min-height: 320px; }
    }
  </style>
</head>
<body>
  <header class="preview-header">MACFOX · Local storefront preview</header>
  <main class="preview-product">
    <div class="preview-image" aria-label="Product image placeholder">Macfox test product</div>
    <section class="preview-info">
      <h1>Macfox E-bike</h1>
      <p>$1,099.00 USD</p>
      <p>Local preview for the student discount storefront extension. No Shopify version is published from this page.</p>
      <div
        class="student-discount-app student-discount-app--center"
        data-student-discount-app
        data-proxy-path="http://127.0.0.1:3000/api/public/student-discounts"
      >
        <button
          type="button"
          class="student-discount-app__trigger student-discount-app__trigger--text"
          data-student-discount-trigger
          aria-haspopup="dialog"
          style="--sd-background:#111111;--sd-text:#ffffff;--sd-radius:0px;--sd-max-width:720px"
        ><span class="student-discount-app__text">Verify your student status and get a student discount</span></button>

        <div id="student-discount-dialog-local" class="student-discount-dialog" data-student-discount-dialog hidden>
          <button type="button" class="student-discount-dialog__backdrop" data-sd-close aria-label="Close student discount dialog"></button>
          <div class="student-discount-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="student-discount-title-local">
            <button type="button" class="student-discount-dialog__close" data-sd-close aria-label="Close">×</button>
            <div class="student-discount-dialog__brand">Macfox test store</div>

            <div data-sd-view="method">
              <button type="button" class="student-discount-dialog__back" data-sd-close><span aria-hidden="true">←</span><span>Back</span></button>
              <h2 id="student-discount-title-local">Preferred Verification Method</h2>
              <p class="student-discount-dialog__lead">Verify quickly and securely by selecting your preferred method.</p>
              <div class="student-discount-dialog__methods">
                <div class="student-discount-dialog__method"><button type="button" class="student-discount-dialog__primary" data-sd-select-email>Get Code by Email</button><p>Sends your student discount to your university email address.</p></div>
                <div class="student-discount-dialog__method"><button type="button" class="student-discount-dialog__primary" data-sd-select-id>Verify with Student ID</button><p>Upload your student ID to get verified and receive your discount by email.</p></div>
              </div>
            </div>

            <div data-sd-view="email" hidden>
              <button type="button" class="student-discount-dialog__back" data-sd-back-method><span aria-hidden="true">←</span><span>Back</span></button>
              <h2>Get Student Discount</h2>
              <p class="student-discount-dialog__lead">Enter the email address provided by your educational institution.</p>
              <form data-sd-email-form novalidate><div class="student-discount-dialog__email-row"><input type="email" name="email" autocomplete="email" maxlength="254" placeholder="School email address" required><button type="submit" disabled>Submit</button></div><p class="student-discount-dialog__error" data-sd-email-error hidden></p></form>
              <div class="student-discount-dialog__alternative" data-sd-alternative hidden><strong>Don't have an eligible school email?</strong><p>Upload your student ID instead to request a review.</p><button type="button" class="student-discount-dialog__primary" data-sd-open-id>Upload student ID</button></div>
            </div>

            <div data-sd-view="student-id" hidden>
              <button type="button" class="student-discount-dialog__back" data-sd-back-method><span aria-hidden="true">←</span><span>Back</span></button>
              <section class="student-discount-dialog__id-section" data-sd-id-section>
                <h2>Verify with Student ID</h2><p>A reviewer will email your discount code after approval.</p>
                <form class="student-discount-dialog__id-form" data-sd-id-form>
                  <label><span>Full name</span><input type="text" name="fullName" autocomplete="name" minlength="2" maxlength="120" required></label>
                  <label><span>Email address</span><input type="email" name="email" autocomplete="email" maxlength="254" required></label>
                  <label class="student-discount-dialog__file"><span>Student ID photo</span><span class="student-discount-dialog__upload"><input type="file" name="studentId" accept="image/jpeg,image/png,image/webp" required><span class="student-discount-dialog__upload-placeholder" data-sd-upload-placeholder><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M16 36h-4a8 8 0 0 1-.7-16A13 13 0 0 1 36 18a9 9 0 0 1 0 18h-4M24 14v24m-8-16 8-8 8 8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg><strong>Upload a clear photo of your student ID</strong></span><img data-sd-id-preview alt="Student ID preview" width="620" height="240" hidden><span class="student-discount-dialog__file-name" data-sd-file-name hidden></span><button type="button" class="student-discount-dialog__clear-file" data-sd-clear-file aria-label="Remove selected student ID photo" hidden>×</button></span><small>JPG, PNG or WebP, up to 5MB. The image is deleted 30 days after review.</small></label>
                  <label class="student-discount-dialog__consent"><input type="checkbox" name="privacyConsent" value="agreed" required><span>I agree to the Privacy Policy and Terms of Service. I understand my image may be analyzed automatically to determine whether it appears to be a student ID, and my information will only be used to review my application and deliver the student discount.</span></label>
                  <label class="student-discount-dialog__honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                  <p class="student-discount-dialog__error" data-sd-id-error hidden></p><button type="submit" class="student-discount-dialog__primary">Submit for review</button>
                </form>
              </section>
            </div>

            <div data-sd-view="success" hidden><div class="student-discount-dialog__success-icon" aria-hidden="true">✓</div><h2 data-sd-success-title>Submitted</h2><p class="student-discount-dialog__lead" data-sd-success-message></p><button type="button" class="student-discount-dialog__primary" data-sd-done>Done</button></div>
            <div class="student-discount-dialog__code-result" data-sd-view="code" hidden><div class="student-discount-dialog__success-icon" aria-hidden="true">✓</div><p class="student-discount-dialog__eyebrow">Student discount approved</p><h2>Your discount code is ready</h2><div class="student-discount-dialog__code-card"><span>Your code</span><strong data-sd-code></strong><button type="button" class="student-discount-dialog__copy" data-sd-copy>Copy code</button></div><p class="student-discount-dialog__code-status" data-sd-code-status hidden></p><p class="student-discount-dialog__code-expiry" data-sd-code-expiry hidden></p><p class="student-discount-dialog__terms-note" data-sd-code-terms hidden></p><p class="student-discount-dialog__copy-feedback" data-sd-copy-feedback aria-live="polite"></p><p class="student-discount-dialog__delivery" data-sd-code-message role="status"></p><p class="student-discount-dialog__checkout-note">Enter this code at checkout to apply your student discount.</p><button type="button" class="student-discount-dialog__primary" data-sd-code-done>Continue shopping</button></div>
          </div>
        </div>
      </div>
      <p class="preview-note">Local API simulation: use an address ending in <strong>.edu</strong> to display a discount code; other addresses continue to student ID upload.</p>
    </section>
  </main>
  <script src="/assets/student-discount.js"></script>
</body>
</html>`;

const contentTypes = {
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
};

const previewServer = createServer((request, response) => {
  const url = new URL(request.url || "/", `http://${host}:${previewPort}`);
  if (url.pathname.startsWith("/assets/")) {
    const filename = url.pathname.slice("/assets/".length);
    if (!/^[a-zA-Z0-9._-]+$/.test(filename)) {
      response.writeHead(400).end("Bad request");
      return;
    }
    const path = join(assetsDirectory, filename);
    const stream = createReadStream(path);
    stream.on("error", () => response.writeHead(404).end("Not found"));
    response.writeHead(200, {"Content-Type": contentTypes[extname(path)] || "application/octet-stream"});
    stream.pipe(response);
    return;
  }
  response.writeHead(200, {"Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store"});
  response.end(html);
});

const sendJson = (response, status, payload) => {
  response.writeHead(status, {
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Headers": "Content-Type",
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Content-Type": "application/json; charset=utf-8",
  });
  response.end(JSON.stringify(payload));
};

const apiServer = createServer((request, response) => {
  if (request.method === "OPTIONS") {
    sendJson(response, 204, {});
    return;
  }
  const url = new URL(request.url || "/", `http://${host}:${apiPort}`);
  const isInfoPath = url.pathname === "/api/public/student-discounts";
  const isClaimPath = url.pathname === "/api/public/student-discounts/claims";
  if (!isInfoPath && !isClaimPath) {
    sendJson(response, 404, {error: {code: "NOT_FOUND", message: "Not found"}});
    return;
  }
  if (request.method === "GET" && isInfoPath) {
    sendJson(response, 200, {data: {campaign_enabled: true, claim_endpoint: "/api/public/student-discounts/claims"}});
    return;
  }
  if (request.method !== "POST" || !isClaimPath) {
    sendJson(response, 405, {error: {code: "METHOD_NOT_ALLOWED", message: "Method not allowed"}});
    return;
  }

  const chunks = [];
  request.on("data", chunk => chunks.push(chunk));
  request.on("end", () => {
    const contentType = String(request.headers["content-type"] || "");
    if (contentType.includes("application/json")) {
      try {
        const body = JSON.parse(Buffer.concat(chunks).toString("utf8"));
        const email = String(body.email || "").toLowerCase();
        if (!/\.(?:edu|ac\.[a-z]{2,})$/.test(email.split("@")[1] || "")) {
          sendJson(response, 422, {error: {code: "EVIDENCE_REQUIRED", message: "A student ID is required for this email."}});
          return;
        }
        sendJson(response, 200, {data: {
          status: "approved",
          discount: {
            code: "TEST-LOCAL2026",
            status: "unused",
            usage_count: 0,
            usage_limit: 1,
            expires_at: new Date(Date.now() + 7 * 86400000).toISOString(),
          },
        }});
      } catch {
        sendJson(response, 400, {error: {code: "INVALID_REQUEST", message: "Invalid request"}});
      }
      return;
    }
    sendJson(response, 202, {data: {status: "pending", discount: null}});
  });
});

const stop = () => {
  previewServer.close();
  apiServer.close();
};

process.on("SIGINT", stop);
process.on("SIGTERM", stop);

previewServer.listen(previewPort, host, () => {
  console.log(`Local storefront preview: http://${host}:${previewPort}/products/macfox-test-product`);
});
apiServer.listen(apiPort, host, () => {
  console.log(`Local preview API: http://${host}:${apiPort}`);
});
