Merlin Unused ImageRemover v1.4.1 with Page Builder support.
Removes all unused images from /pub/media that are not being used by products, cms pages, pagebuilder or Amasty's MegaMenu

** Usage
Preview (no changes), verbose list:

bin/magento merlin:image-remover:scan --dry-run -v


Faster preview (skip deep JSON/serialized decoding but still DB-wide):

bin/magento merlin:image-remover:scan --dry-run --db-fast


Skip DB-wide pass entirely:

bin/magento merlin:image-remover:scan --dry-run --no-db-scan


bin/magento merlin:image-remover:scan

Actually delete (prompts 'yes/no'):

Delete without prompt:

bin/magento merlin:image-remover:scan -y


Exclude additional prefixes under pub/media (repeat -e):

bin/magento merlin:image-remover:scan --dry-run -e amasty/webp/wysiwyg -e logo -e vendor_x/cache


** Built-in protections

Skips cache/system dirs: catalog/product/cache/, tmp/, captcha/, import/, downloadable/tmp/.

Skips Amasty cache: amasty/, and hard-skips amasty/webp/ (including amasty/webp/wysiwyg/).

Hard-skip logo/ to never delete store logos by mistake.

Page Builder extractor preserves background images/icons referenced via data-background-images JSON.

Preserves favicons/logos and any refs found anywhere in your DB (thanks to the whole-DB + config scans).

Amasty Mega Menu extractor preserves icons/banners/backgrounds from mega menu configs.


** Optional Safeguards:
If your homepage uses a known PB folder, you can temporarily exclude it:

bin/magento merlin:image-remover:scan --dry-run -e wysiwyg/homepage -e pagebuilder


** Changelog


v.1.4.1
Fix: Issue with detecting all pagebuilder images fixed.

v1.4.0
New: Page Builder extractor parses data-background-images JSON (even when HTML-entity encoded), src/srcset, and inline background-image:url(...) to keep PB assets.
Minor: expanded generic HTML parsing for srcset and data-src attributes.

v1.3.3
Fix PHP syntax issues in previous builds; clean, validated classes.
Keep logo/* by default and read logos/favicons from config.

v1.3.2
Preserve store logos & favicons via ConfigExtractor of core_config_data (e.g., design/header/logo_src, design/email/logo, sales/identity/logo, design/head/shortcut_icon).
Default protection for logo/ (skip deletion).

v1.3.1
Hard-skip pub/media/amasty/webp/ (incl. wysiwyg/), add --exclude.

v1.3.0
Add Amasty Mega Menu extractor.

v1.2.0
Intensive DB scan with JSON/serialized/URL/HTML decoding; stop ignoring favicon/.

v1.1.0
Whole-DB scanner enabled by default; --no-db-scan to skip.

v1.0.x
Initial product/category/CMS scans, dry-run, confirmations.
