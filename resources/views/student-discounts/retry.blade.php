<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Verify with Student ID | {{ $storeName }}</title>
    <style>
        :root { color-scheme: light; --sd-primary: {{ $primaryColor }}; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f2f2; color: #111; font-family: Arial, Helvetica, sans-serif; }
        .page { width: min(100%, 1120px); margin: 0 auto; padding: 32px 20px 56px; }
        .card { overflow: hidden; border: 1px solid #dedede; background: #fff; box-shadow: 0 18px 60px rgba(0,0,0,.08); }
        .brand { display: flex; min-height: 120px; align-items: center; justify-content: center; padding: 28px; border-bottom: 1px solid #e4e4e4; }
        .brand img { display: block; max-width: 280px; max-height: 72px; object-fit: contain; }
        .brand strong { font-size: clamp(26px, 4vw, 42px); }
        .content { padding: clamp(28px, 6vw, 72px); }
        h1 { margin: 0; font-size: clamp(36px, 6vw, 64px); line-height: 1.05; text-align: center; }
        .lead { max-width: 820px; margin: 24px auto 42px; color: #505050; font-size: clamp(18px, 2.4vw, 25px); line-height: 1.5; text-align: center; }
        form { display: grid; grid-template-columns: 1fr 1fr; gap: 24px 28px; }
        label { display: grid; gap: 10px; font-size: 18px; font-weight: 700; }
        input[type="text"], input[type="email"] { width: 100%; min-height: 64px; padding: 0 20px; border: 1px solid #999; border-radius: 0; background: #fff; color: #111; font: inherit; font-size: 18px; }
        input:focus { outline: 3px solid var(--sd-primary); outline-offset: 1px; border-color: var(--sd-primary); }
        .wide { grid-column: 1 / -1; }
        .upload { position: relative; display: grid; min-height: 260px; place-items: center; border: 2px dashed #999; background: #fff; text-align: center; cursor: pointer; }
        .upload input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .upload-copy { display: grid; place-items: center; gap: 14px; padding: 34px; pointer-events: none; }
        .upload-copy svg { width: 52px; height: 52px; }
        .upload-copy strong { font-size: clamp(20px, 3vw, 28px); }
        small { color: #666; font-size: 15px; font-weight: 400; line-height: 1.5; }
        .consent { display: flex; align-items: flex-start; gap: 14px; font-size: 16px; font-weight: 400; line-height: 1.5; cursor: pointer; }
        .consent input { flex: 0 0 26px; width: 26px; height: 26px; margin: 0; accent-color: var(--sd-primary); }
        .errors { grid-column: 1 / -1; margin: 0; padding: 16px 20px; border: 1px solid #e6aaa3; background: #fff5f4; color: #a52418; font-size: 16px; line-height: 1.5; }
        .errors ul { margin: 0; padding-left: 20px; }
        .primary { display: flex; min-width: min(100%, 360px); min-height: 66px; align-items: center; justify-content: center; margin: 8px auto 0; padding: 0 32px; border: 0; border-radius: 0; background: var(--sd-primary); color: #fff; font: inherit; font-size: 20px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .primary:hover, .primary:focus { color: #fff; filter: brightness(.92); }
        .state { max-width: 760px; margin: 0 auto; text-align: center; }
        .state-icon { display: grid; width: 88px; height: 88px; place-items: center; margin: 0 auto 28px; border-radius: 50%; background: #1f9558; color: #fff; font-size: 48px; }
        .state h1 { font-size: clamp(38px, 6vw, 60px); }
        .state .primary { margin-top: 34px; }
        .honeypot { position: absolute !important; left: -10000px !important; width: 1px !important; overflow: hidden !important; }
        @media (max-width: 720px) {
            .page { padding: 0; }
            .card { min-height: 100vh; border: 0; box-shadow: none; }
            .brand { min-height: 96px; }
            .content { padding: 34px 20px 48px; }
            form { grid-template-columns: 1fr; }
            .wide { grid-column: auto; }
            .upload { min-height: 220px; }
            .primary { width: 100%; }
        }
    </style>
</head>
<body>
<main class="page">
    <section class="card">
        <header class="brand">
            @if (filled($logoUrl))
                <img src="{{ $logoUrl }}" alt="{{ $storeName }}">
            @else
                <strong>{{ $storeName }}</strong>
            @endif
        </header>

        <div class="content">
            @if ($state === 'form')
                <h1>Verify with Student ID</h1>
                <p class="lead">Upload a clear photo of your student ID to request verification.</p>

                <form method="post" action="{{ $formAction }}" enctype="multipart/form-data">
                    <label>
                        <span>Full name</span>
                        <input type="text" name="full_name" autocomplete="name" minlength="2" maxlength="120" value="{{ $submittedName }}" required>
                    </label>
                    <label>
                        <span>Email address</span>
                        <input type="email" name="email" autocomplete="email" maxlength="320" value="{{ $submittedEmail }}" required>
                    </label>
                    <label class="wide">
                        <span>Student ID photo</span>
                        <span class="upload">
                            <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required>
                            <span class="upload-copy">
                                <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M16 36h-4a8 8 0 0 1-.7-16A13 13 0 0 1 36 18a9 9 0 0 1 0 18h-4M24 14v24m-8-16 8-8 8 8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <strong>Upload a clear photo of your student ID</strong>
                            </span>
                        </span>
                        <small>JPG, PNG or WebP, up to 5MB. The image is deleted 30 days after review.</small>
                    </label>
                    <label class="wide consent">
                        <input type="checkbox" name="privacy_consent" value="true" required>
                        <span>I agree to the Privacy Policy and Terms of Service. My information will only be used to verify my eligibility for the student discount.</span>
                    </label>
                    <label class="honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    @if ($errors !== [])
                        <div class="errors" role="alert"><ul>@foreach ($errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    <button type="submit" class="wide primary">Submit for review</button>
                </form>
            @elseif ($state === 'success')
                <div class="state">
                    <div class="state-icon" aria-hidden="true">✓</div>
                    <h1>Submitted for review</h1>
                    <p class="lead">{{ $message }}</p>
                    <a class="primary" href="{{ $storeUrl }}">Done</a>
                </div>
            @else
                <div class="state">
                    <h1>This verification link is unavailable</h1>
                    <p class="lead">{{ $message }}</p>
                    <a class="primary" href="{{ $storeUrl }}">Done</a>
                </div>
            @endif
        </div>
    </section>
</main>
</body>
</html>
