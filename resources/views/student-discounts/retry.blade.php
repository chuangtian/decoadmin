<style>
    .student-verification-page {
        --student-verification-primary: {{ $primaryColor }};
        width: 100vw;
        margin-left: calc(50% - 50vw);
        padding: clamp(36px, 5vw, 64px) 20px;
        background: #f4f4f4;
        color: #111;
        font-family: inherit;
    }
    .student-verification-page * { box-sizing: border-box; }
    .student-verification-page__inner { width: min(100%, 920px); margin: 0 auto; }
    .student-verification-page__panel { padding: clamp(28px, 5vw, 52px); border: 1px solid #e1e1e1; background: #fff; }
    .student-verification-page h1 { margin: 0; color: #111; font-size: clamp(32px, 4vw, 46px); font-weight: 700; line-height: 1.15; text-align: center; }
    .student-verification-page__lead { max-width: 660px; margin: 16px auto 34px; color: #5a5a5a; font-size: clamp(16px, 2vw, 19px); line-height: 1.55; text-align: center; }
    .student-verification-page form { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 24px; }
    .student-verification-page label { display: grid; gap: 8px; margin: 0; color: #111; font-size: 16px; font-weight: 600; line-height: 1.4; }
    .student-verification-page input[type="text"],
    .student-verification-page input[type="email"] { width: 100%; min-height: 54px; padding: 0 16px; border: 1px solid #aaa; border-radius: 0; background: #fff; color: #111; font: inherit; font-size: 16px; box-shadow: none; }
    .student-verification-page input:focus { border-color: var(--student-verification-primary); outline: 2px solid var(--student-verification-primary); outline-offset: 1px; }
    .student-verification-page__wide { grid-column: 1 / -1; }
    .student-verification-page__upload { position: relative; display: grid; min-height: 190px; place-items: center; border: 1.5px dashed #999; background: #fafafa; text-align: center; cursor: pointer; transition: border-color .2s ease, background .2s ease; }
    .student-verification-page__upload:hover { border-color: var(--student-verification-primary); background: #f7f7f7; }
    .student-verification-page__upload input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    .student-verification-page__upload-copy { display: grid; place-items: center; gap: 10px; padding: 24px; pointer-events: none; }
    .student-verification-page__upload-copy svg { width: 40px; height: 40px; }
    .student-verification-page__upload-copy strong { font-size: clamp(17px, 2vw, 21px); font-weight: 600; }
    .student-verification-page small { color: #6a6a6a; font-size: 13px; font-weight: 400; line-height: 1.5; }
    .student-verification-page__consent { display: flex !important; align-items: flex-start; gap: 12px !important; color: #333 !important; font-size: 14px !important; font-weight: 400 !important; line-height: 1.5 !important; cursor: pointer; }
    .student-verification-page__consent input { flex: 0 0 22px; width: 22px; height: 22px; margin: 0; accent-color: var(--student-verification-primary); }
    .student-verification-page__errors { grid-column: 1 / -1; margin: 0; padding: 13px 16px; border: 1px solid #e6aaa3; background: #fff5f4; color: #a52418; font-size: 14px; line-height: 1.5; }
    .student-verification-page__errors ul { margin: 0; padding-left: 20px; }
    .student-verification-page__primary { display: flex; width: min(100%, 300px); min-height: 54px; align-items: center; justify-content: center; margin: 6px auto 0; padding: 0 24px; border: 0; border-radius: 0; background: var(--student-verification-primary); color: #fff; font: inherit; font-size: 16px; font-weight: 600; line-height: 1; text-decoration: none; cursor: pointer; transition: filter .2s ease; }
    .student-verification-page__primary:hover,
    .student-verification-page__primary:focus { color: #fff; filter: brightness(.9); }
    .student-verification-page__state { max-width: 660px; margin: 0 auto; padding: 12px 0; text-align: center; }
    .student-verification-page__state-icon { display: grid; width: 72px; height: 72px; place-items: center; margin: 0 auto 22px; border-radius: 50%; background: #1f9558; color: #fff; font-size: 38px; }
    .student-verification-page__state .student-verification-page__primary { margin-top: 26px; }
    .student-verification-page__honeypot { position: absolute !important; left: -10000px !important; width: 1px !important; overflow: hidden !important; }
    @media (max-width: 720px) {
        .student-verification-page { padding: 24px 14px 40px; }
        .student-verification-page__panel { padding: 28px 18px 34px; }
        .student-verification-page form { grid-template-columns: 1fr; gap: 18px; }
        .student-verification-page__wide { grid-column: auto; }
        .student-verification-page__upload { min-height: 170px; }
        .student-verification-page__primary { width: 100%; }
    }
</style>

<section class="student-verification-page">
    <div class="student-verification-page__inner">
        <div class="student-verification-page__panel">
            @if ($state === 'form')
                <h1>Verify with Student ID</h1>
                <p class="student-verification-page__lead">Upload a clear photo of your student ID to request verification.</p>

                <form method="post" action="{{ $formAction }}" enctype="multipart/form-data">
                    <label>
                        <span>Full name</span>
                        <input type="text" name="full_name" autocomplete="name" minlength="2" maxlength="120" value="{{ $submittedName }}" required>
                    </label>
                    <label>
                        <span>Email address</span>
                        <input type="email" name="email" autocomplete="email" maxlength="320" value="{{ $submittedEmail }}" required>
                    </label>
                    <label class="student-verification-page__wide">
                        <span>Student ID photo</span>
                        <span class="student-verification-page__upload">
                            <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required>
                            <span class="student-verification-page__upload-copy">
                                <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M16 36h-4a8 8 0 0 1-.7-16A13 13 0 0 1 36 18a9 9 0 0 1 0 18h-4M24 14v24m-8-16 8-8 8 8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <strong>Upload a clear photo of your student ID</strong>
                            </span>
                        </span>
                        <small>JPG, PNG or WebP, up to 5MB. The image is deleted 30 days after review.</small>
                    </label>
                    <label class="student-verification-page__wide student-verification-page__consent">
                        <input type="checkbox" name="privacy_consent" value="true" required>
                        <span>I agree to the Privacy Policy and Terms of Service. My information will only be used to verify my eligibility for the student discount.</span>
                    </label>
                    <label class="student-verification-page__honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    @if ($errors !== [])
                        <div class="student-verification-page__errors" role="alert"><ul>@foreach ($errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    <button type="submit" class="student-verification-page__wide student-verification-page__primary">Submit for review</button>
                </form>
            @elseif ($state === 'success')
                <div class="student-verification-page__state">
                    <div class="student-verification-page__state-icon" aria-hidden="true">✓</div>
                    <h1>Submitted for review</h1>
                    <p class="student-verification-page__lead">{{ $message }}</p>
                    <a class="student-verification-page__primary" href="{{ $storeUrl }}">Done</a>
                </div>
            @else
                <div class="student-verification-page__state">
                    <h1>This verification link is unavailable</h1>
                    <p class="student-verification-page__lead">{{ $message }}</p>
                    <a class="student-verification-page__primary" href="{{ $storeUrl }}">Done</a>
                </div>
            @endif
        </div>
    </div>
</section>
