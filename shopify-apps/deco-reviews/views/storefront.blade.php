<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ url('/api/shopify-app/deco-reviews/assets/storefront.css') }}">
    <script src="{{ url('/api/shopify-app/deco-reviews/assets/storefront.js') }}" defer></script>
    <title>Customer reviews</title>
</head>
<body>
@php
    $reviewForm = $formConfig ?? [];
    $allowPhotos = (bool) ($reviewForm['allow_photos'] ?? false);
    $allowVideo = (bool) ($reviewForm['allow_video'] ?? false);
    $mediaAccept = implode(',', array_filter([$allowPhotos ? 'image/*' : null, $allowVideo ? 'video/*' : null]));
    $csrfToken = request()->hasSession() ? csrf_token() : '';
@endphp
<main class="dr-widget" data-deco-reviews data-feed-url="{{ $feedUrl }}" data-mode="{{ $widgetMode ?? 'reviews' }}" data-csrf="{{ $csrfToken }}" data-form-preview="{{ ($formPreview ?? false) ? 'true' : 'false' }}" data-allow-photos="{{ $allowPhotos ? 'true' : 'false' }}" data-allow-video="{{ $allowVideo ? 'true' : 'false' }}" data-thanks="{{ $reviewForm['thank_you'] ?? 'Thank you. Your review was submitted for moderation.' }}" data-preview-message="仅预览，没有提交或保存评价" data-required-answer="Please choose at least one answer." data-media-error="Upload up to 5 photos or 1 video.">
    @if ($feedUrl)
        <header class="dr-header">
            <h1 class="dr-heading" data-dr-heading>Customer reviews</h1>
            <div class="dr-summary" data-dr-summary></div>
        </header>
        <div class="dr-toolbar">
            <label class="dr-field">Sort
                <select class="dr-select" data-dr-sort><option value="newest">Newest</option><option value="oldest">Oldest</option><option value="highest">Highest rating</option><option value="lowest">Lowest rating</option></select>
            </label>
            <label class="dr-field">Rating
                <select class="dr-select" data-dr-rating><option value="">All ratings</option><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars</option><option value="2">2 stars</option><option value="1">1 star</option></select>
            </label>
        </div>
        <p class="dr-status" role="status" aria-live="polite" data-dr-status></p>
        <section class="dr-list" aria-label="Customer reviews" data-dr-list></section>
        <nav class="dr-pagination" aria-label="Review pages" data-dr-pagination></nav>
    @endif
    @if (($formCompleted ?? false) && $submitUrl)
        <section class="dr-form" aria-labelledby="dr-completed-heading">
            <h2 id="dr-completed-heading">{{ $reviewForm['thank_you'] ?? 'Thank you. Your review has been received.' }}</h2>
            @if (!empty($invitationProduct['title']))
                <p>{{ $invitationProduct['title'] }}</p>
            @endif
            <p>This review link has already been used. No further action is needed.</p>
        </section>
    @elseif ($submitUrl || ($formPreview ?? false))
        <form class="dr-form" action="{{ $submitUrl ?? '' }}" method="post" enctype="multipart/form-data" data-dr-form>
            @if ($csrfToken)
                @csrf
            @endif
            <input type="hidden" name="form_version" value="{{ $reviewForm['version'] ?? 'initial' }}">
            <label class="dr-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
            <h2>{{ $reviewForm['heading'] ?? 'Review your purchase' }}</h2>
            @if (!empty($reviewForm['description']))
                <p>{{ $reviewForm['description'] }}</p>
            @endif
            @if (!empty($invitationProduct['title']))
                <p>{{ $invitationProduct['title'] }}</p>
            @endif
            <div class="dr-form__grid">
                <label class="dr-field">{{ $reviewForm['name_label'] ?? 'Name' }}<input class="dr-input" name="author_name" required maxlength="120" autocomplete="name"></label>
                @if ($collectEmail ?? false)
                    <label class="dr-field">Email<input class="dr-input" type="email" name="author_email" required maxlength="254" autocomplete="email"></label>
                @endif
                <label class="dr-field">Rating<select class="dr-select" name="rating" required><option value="">Choose a rating</option><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars</option><option value="2">2 stars</option><option value="1">1 star</option></select></label>
                <label class="dr-field">{{ $reviewForm['title_label'] ?? 'Title' }}<input class="dr-input" name="title" maxlength="200"></label>
            </div>
            <label class="dr-field">{{ $reviewForm['body_label'] ?? 'Review' }}<textarea class="dr-textarea" name="body" required maxlength="10000"></textarea></label>
            @foreach (($reviewForm['questions'] ?? []) as $question)
                @php
                    $multipleQuestion = ($question['type'] ?? '') === 'multiple';
                    $requiredQuestion = (bool) ($question['required'] ?? false);
                @endphp
                <fieldset class="dr-question" {{ $multipleQuestion ? 'data-answer-multiple' : '' }} {{ $requiredQuestion ? 'data-required' : '' }}>
                    <legend>{{ $question['label'] }}</legend>
                    @if (($question['type'] ?? '') === 'single')
                        <div class="dr-question__options">
                            @foreach (($question['options'] ?? []) as $option)
                                <label><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $option }}" {{ $requiredQuestion && $loop->first ? 'required' : '' }}> {{ $option }}</label>
                            @endforeach
                        </div>
                    @elseif (($question['type'] ?? '') === 'multiple')
                        <div class="dr-question__options">
                            @foreach (($question['options'] ?? []) as $option)
                                <label><input type="checkbox" name="answers[{{ $question['id'] }}][]" value="{{ $option }}"> {{ $option }}</label>
                            @endforeach
                        </div>
                    @elseif (($question['type'] ?? '') === 'scale')
                        <select class="dr-select" name="answers[{{ $question['id'] }}]" {{ $requiredQuestion ? 'required' : '' }}>
                            <option value="">Choose</option>
                            @for ($value = (int) ($question['min'] ?? 1); $value <= (int) ($question['max'] ?? 5); $value++)
                                <option value="{{ $value }}">{{ $value }}</option>
                            @endfor
                        </select>
                    @endif
                    <small class="dr-question__visibility">{{ ($question['public'] ?? false) ? 'This answer may be shown publicly.' : 'Only the store can see this answer.' }}</small>
                </fieldset>
            @endforeach
            @if ($allowPhotos || $allowVideo)
                <label class="dr-field">Photos or video<input class="dr-input" type="file" name="media[]" accept="{{ $mediaAccept }}" multiple></label>
                <small>Upload up to 5 photos or 1 video.</small>
            @endif
            <label class="dr-checkbox"><input type="checkbox" name="consent" value="1" required><span>I consent to this review and its media being published according to the store’s review terms.</span></label>
            <button class="dr-button" type="submit">{{ $reviewForm['submit_label'] ?? 'Submit review' }}</button>
            <p role="status" aria-live="polite" data-dr-form-status></p>
        </form>
    @endif
</main>
</body>
</html>
