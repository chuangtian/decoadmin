<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="/api/shopify-app/deco-reviews/assets/storefront.css">
    <script src="/api/shopify-app/deco-reviews/assets/storefront.js" defer></script>
    <title>Customer reviews</title>
</head>
<body>
<main class="dr-widget" data-deco-reviews data-feed-url="{{ $feedUrl }}" data-mode="{{ $widgetMode ?? 'reviews' }}" data-csrf="{{ csrf_token() }}" data-media-error="Upload up to 5 photos or 1 video.">
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
    @if ($submitUrl)
        <form class="dr-form" action="{{ $submitUrl }}" method="post" enctype="multipart/form-data" data-dr-form>
            @csrf
            <h2>Review {{ $invitationProduct['title'] ?? 'your purchase' }}</h2>
            <div class="dr-form__grid">
                <label class="dr-field">Name<input class="dr-input" name="author_name" required maxlength="120" autocomplete="name"></label>
                <label class="dr-field">Rating<select class="dr-select" name="rating" required><option value="">Choose a rating</option><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars</option><option value="2">2 stars</option><option value="1">1 star</option></select></label>
                <label class="dr-field">Title<input class="dr-input" name="title" maxlength="160"></label>
            </div>
            <label class="dr-field">Review<textarea class="dr-textarea" name="body" required maxlength="5000"></textarea></label>
            <label class="dr-field">Photos or video<input class="dr-input" type="file" name="media[]" accept="image/*,video/*" multiple></label>
            <small>Upload up to 5 photos or 1 video.</small>
            <label class="dr-checkbox"><input type="checkbox" name="consent" value="1" required><span>I consent to this review and its media being published according to the store’s review terms.</span></label>
            <button class="dr-button" type="submit">Submit review</button>
            <p role="status" aria-live="polite" data-dr-form-status></p>
        </form>
    @endif
</main>
</body>
</html>
