# Student discount App Home

This Admin UI extension is the Shopify-side entry point for Deco student
discount. It checks the current shop's DecoAdmin connection, bootstraps the
environment-specific App Proxy path, opens the matching DecoAdmin management
page, and provides a deep link for adding the Theme App Extension block.

The extension intentionally contains no campaign management, review workflow,
Gemini configuration, discount generation, or database logic. Those behaviors
remain in the DecoAdmin Laravel application.

Run validation and builds from `shopify-apps/student-discount/` using the
environment-specific commands documented in that directory's main README.
