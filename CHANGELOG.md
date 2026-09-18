# Changes in 3.0

## For customers

- Orders sitting on one of the shop's own processing statuses show in a customer's order history again; those
  statuses had been created hidden from the storefront.
- A design made from a customer's own description is now fetched by its key rather than by its number, so one
  customer's artwork no longer comes back to anyone who asks for the id. Every design made before the key
  existed was given one.
- The font range is the twenty fonts orders actually name. A design still opening in a font that went now opens
  in Pincher-Brothers rather than in nothing.
- Every font's sample picture is drawn from the font file itself, so a sample can never be of a different
  typeface from the font beside it. Two font names that read wrongly were put right.
- A shop that promised free delivery over a certain order value keeps promising it over that value: the number
  was carried onto Magento's own free delivery carrier before the old copies were dropped.
- A shop already running the priority access gate keeps the word it was using; the password is a setting now
  rather than something only a deploy could change. A shop that had no word saved is given a random one, which
  is read and replaced at Priority Access, rather than a word anybody could read in the code.
- The footer's first legal line prints the current year from the clock instead of a year that was typed in and
  went stale every January.

## For staff

- Two columns on the order products grid were renamed — "Printed At" and "Device" became "Last printed" and
  "Last printed on" — and every admin's saved grid arrangement was rewritten to match, so nobody loses the
  layout they had.
- Printed now means the print room took the file. Rows that had been marked printed only so a regenerated file
  would not be picked up as new work were moved onto the regenerated flag and given a regeneration date.
- The Designer section sits directly under General on the product form in every attribute set, with every
  designer attribute in it and the attribute notes refreshed.
- Royal Mail's settings sit in a carrier group beside every other carrier, and its client secret is encrypted
  rather than saved in the clear.
- The face service's settings moved to Stores > Configuration > AI > Face V2, and the service is called Face V2
  everywhere, including in the usage report and the log filters.
- The face service log and the two tables of AI notes about an upload were folded into the AI log, so a scan is
  one row and a note is in one place.
- Settings that were the same number on every product became shop settings, set once: the designer's endpoints,
  the gallery's upload ceilings and endpoint, and the prompt length and redraw limits.
- Tooltips on individual option rows — a frame finish, a mount size, a roll length — were stripped; a tooltip
  belongs on the tool's own heading.
- The Gallery tool's accepted file types are chosen from the shop's format list rather than typed in as media
  types nothing checked.
- Designer > Quality settings were carried over to the points scoring, sorted into Score, Detection, Bands and
  Messages.
- The delivery banner works its own wording out from the cut off, the working days and the carrier's transit, so
  the three fixed sentences an admin used to pick between are gone.
- The old Device Enabled yes/no became the print workflow it always meant: the print room app pulling files, or
  rendering them on order for download.
- `core_config_data` was cleaned once: settings 3.0 no longer reads, settings belonging to extensions that are
  no longer installed, and settings saved at their own default so a new default could never reach the store.

## Data and migration

- `CopyGiftwrapColors` lifts every colour out of `ben_giftwrap_color` keeping the id it holds, because that id is
  what a design's default colour names and what every order already placed recorded as the colour its message is
  printed in. `KeyDesignsToColorCatalogue` then keys the design to `ben_color` and drops the old table.
Everything below runs as data patches inside `setup:upgrade`, in this order. Every patch asks first whether the
module, table or column it needs is actually there, and a no is one line in the log and nothing else — the three
sites do not run the same modules. Every patch can be run again over a database it has already been through.

**Designs, products and order items**

1. The shop's own processing statuses are made visible on the storefront.
2. Design types are renamed: tile became original, face became photo, generated became ai. The type column became
   NOT NULL in this release, so a design that had no type at all is given the type it has always behaved as.
3. Every art design already on the shop is marked personal and given a key. The catalogue's own Create Your Own
   paper carries no description, so it is left unkeyed.
4. A product carrying giftwrap tools that predates designer types is set to the giftwrap type; a product with
   designer tools and no tool belonging to another type is set to the photo type.
5. The admin tool configuration's source classes are renamed to their new home.
6. The Gallery tool's saved media types become format names; a media type the format registry does not know is
   dropped, which falls back to the upload profile's own ceiling.
7. Every giftwrap order item the old checkout took is rewritten into the shape the designer writes:
   `designer_active_data` and `designer_type`, with the original options kept beside them under
   `giftwrap_legacy`. The roll length is named by the hash of the configured row of that length; items sold at a
   length the picker no longer offers carry the length alone and the print room prints what the order says. An
   item that already carries designer data is skipped, and an unreadable item is logged by id and skipped rather
   than failing the upgrade. **Cart items are deliberately not converted** — see the pre-upgrade list.

**Fonts**

8. The font catalogue is copied out of `ben_giftwrap_font` into `ben_font`, each row keeping the id it holds,
    because a design's default font, the store font setting and the art range's typography setting all name it.
9. The fonts the designer used to hide with a hard coded list are switched off with the new Enabled flag.
10. The range is cut to the twenty fonts orders name. The patch only runs when the table is recognisably that
    list — at least fifteen of the twenty present under their own name — so a shop that built its own range is
    left alone with a warning.
11. Designs still naming a font that went are moved to Pincher-Brothers. A store font setting pointing at a font
    that went is reported rather than moved.
12. Designs are keyed to the font catalogue: the foreign key is added by hand, under the name declarative schema
    would have generated, and `ben_giftwrap_font` is dropped once its rows are known to be in the catalogue. The
    key could not be declared in `db_schema.xml` because `ben_font` is still empty when the schema step runs.
13. Every font in the catalogue is given a drawn sample and its uploaded SVG is removed.

**Design categories**

14. `ben_giftwrap_design_category` has duplicate design/category pairs removed, keeping the lowest id of each and
    naming what went in the log, and then gets its unique key added by hand under the name declarative schema
    would have generated. The declaration is out of `Ben_Giftwrap/etc/db_schema.xml` for 3.0 only, because one
    duplicate pair on a live database would abort `setup:upgrade` with the schema half applied. It goes back in
    the release after 3.0, as do the NOT NULL columns. **The two foreign keys on that table are still declarative
    and still run before any patch** — see the pre-upgrade list.

**Assets**

15. The saved expiry numbers are carried over to the one number per role the group has now; where two old fields
    fed one role the larger of the two wins, and the old rows are deleted.
16. Every asset already in the table is given the role it would be created with today, working from the strongest
    role down. Roles that never expire have their expiry cleared. A row nothing points at, and whose kind says
    nothing certain, keeps no role rather than a guess.
17. Every asset is given the kind whatever made it would name today, worked out from what points at the row
    first and the directory it was written into second. What still cannot be placed is left empty rather than
    guessed at, and counted in the log.
18. Both asset backfills are run once more, in the other order, kinds first. This is the pass that gives an
    asset nothing points at the role its kind settles: roles are backfilled before kinds are, so on the first
    pass every kind is still empty and only the assets something points at come out with a role. It also
    catches a table that arrives after the patches did - both first ran while ben_asset held a handful of rows
    and a live import afterwards brought in twelve thousand made before the kind and role columns existed.
    Both only touch rows with nothing in the column yet, so a coloured table is untouched.

**Logs**

19. Rows written by the face service are renamed from the old provider name to Face V2.
20. `ben_giftwrap_face_log` is folded into `ben_ai_generation` and dropped. A face log row is matched to its
    generation by the photograph — reached through the normalised copy that was actually sent — with the kinds
    agreeing, within five minutes, each generation claimed once; a row that matches nothing is inserted as a
    generation of its own. The two keep-warm flags go with it.
21. `ben_designer_asset_quality` and `ben_giftwrap_face_summary` are folded into `ben_ai_asset_note` and dropped,
    with each note's generation hash resolved to the generation's own id as a foreign key on the way across. A
    note whose generation has since been deleted comes over without one.

**Print and admin**

22. Products marked printed with no print date are moved onto the regeneration flag.
23. Saved order-product grid layouts have the two renamed print columns rewritten, as column keys, as the sorted
    field and as filter keys.
24. The Designer attribute group is sorted directly under General in every attribute set and every designer
    attribute placed in it.
25. Tool option schemas that still say "varchar" are rewritten to say "string".
26. Row-level tooltips are stripped from every dynamic-row option schema and from the rows saved against a
    product.

**Settings moved**

27. Device Enabled becomes the print workflow setting, at every scope that had its own answer.
28. The Gallery group's upload endpoint moves to the Endpoints group; Allow Multiple Uploads is dropped, since a
    design is one photo.
29. Designer > Quality settings become the points scoring's Score, Detection, Bands and Messages fields; a
    penalty carries over as the negative number it always was.
30. The face service settings move to AI > Face V2. The API key is deleted — nothing sends one, the service is
    only reached over the internal network — and so is the queue wait, which is the service's own setting now.
31. Royal Mail's settings move from `shipping_api/royal_mail` to `shipping_api/carrier_rm`, and the client secret
    is encrypted on the way over.
32. The giftwrap and promotion copies of the free delivery threshold, and the GiftwrapSize tool's copy of it, are
    carried onto Magento's free shipping carrier and then dropped. A number already saved on the carrier is the
    admin's later decision and always wins. **The carrier's own on/off flag is left alone** — see the pre-upgrade
    list.
33. The priority access settings a shop was already using - the switch, the two messages and the password, which
    travels as the ciphertext it already is - are copied from the old Promotion path into the Coming Soon
    section, in every scope they were set in, and only where nothing has been typed there already. A shop with
    nothing to carry over is given a random password and told so in the log, never in the repository, and it is
    read and replaced at Priority Access.
34. The footer's copyright holder moves to a field of its own and the year is printed from the clock. The retired
    Trustwave URL rows go at the same time.

**Settings dropped, nothing carried over**

35. The three fixed delivery sentences, the print strip promotion's four settings (the strip prints a scan link
    now), the offshore postcode lists at every scope for every carrier (only DPD ever refused a postcode of its
    own and it left with 3.0), the prompt length and redraw limits every product carried, the designer endpoints and the older Quality tool's thresholds and
    wording, the gallery options that became shop settings, saved feed wording that still spelled out roll
    lengths and paper width by hand, and the QualityScore tool from the products it was tried out on.

**Last, and unattended**

36. Redundant configuration is purged: settings 3.0 does not read, settings of modules that are no longer
    installed, and settings saved at their own default. It waits for every patch above that still has a path to
    carry. The whole list is written to the log before a single row is deleted. Paths under `payment/`,
    `carriers/` and `web/secure/` are pinned and never removed whatever the audit says — a payment or carrier
    setting removed in the release window is a shop that stops taking money, and a secure base URL removed is a
    shop served over plain HTTP. Those are reported for a person to deal with. The value is never logged, only
    the path and scope.
37. The tables and `setup_module` rows left behind by Amazon, Dotdigital, Klarna, Vertex, Yotpo and a handful of
    Magento modules are dropped, having been dumped first. If the dump directory cannot be written to, nothing is
    dropped at all and the upgrade carries on. Only table names and row counts are logged, never contents.

### Before the upgrade, on each live site

- **Disable Ben_Personalise before `setup:upgrade`.** The module is shelved but still live: it registers a
  five-minute cron, a checkout plugin and a frontend observer, and the plugin writes the legacy order item shape
  that the giftwrap order item conversion is converting away. Leaving it on means new items in the old shape from
  the moment the release lands, and a cron rewriting order item SKUs every five minutes. A patch cannot switch
  off the module it is running inside, so this is a hand step:

  ```
  bin/magento module:disable Ben_Personalise
  # or set 'Ben_Personalise' => 0 in app/etc/config.php and commit it with the release
  bin/magento setup:upgrade
  ```

  Nothing is deleted: the module's code, its tables and its order data all stay, and a disabled module's patches
  simply do not run. Verified on dev on 17 September 2026 that no enabled module depends on it.
- **Clear active carts at the cutover.** Old-designer cart items are not converted and cannot be — their shape no
  longer exists in the code, and a cart is priced again on every load anyway.
- **Run `cache:flush` after the release, not `cache:clean`**, or the first regenerate fatals on an observer that
  has been removed.
- **Check `ben_giftwrap_design_category` for orphans and NULLs immediately before the deploy.** Its NOT NULL
  columns and two foreign keys are still declarative and run before any patch, so a bad row aborts the upgrade.
  Dev is 0/0/0 across 931 rows.
- **Null any orphaned `ben_marketing_recovery_email.recovered_order_id` and `customer_id`, and any orphaned
  `ben_marketing_link_click.converted_order_id`** — rows pointing at orders or customers that no longer exist —
  or the new foreign keys fail to create. Dev had 0.
- **Agree where the leftover-table dumps go and clear them afterwards.** The drop writes dotdigital, amazon and
  yotpo data into that site's `backups/work/removed-modules`. Running
  `bin/magento config:remove-unused --dry-run --drop-leftovers` on live first shows the list before the upgrade
  does it.
- **Turn Magento's free shipping carrier on if the shop wants free delivery.** The threshold number is carried
  over, but the carrier's on/off flag is deliberately not touched: enabling free shipping is a shipping decision,
  not a data migration.
- **Design 488, the old face paper, is gone with its face crops.** 1,153 historical items cannot be reprinted.
  If reprints are ever needed, restore it as type photo.
- Found by the 2026-09-17 rehearsal on a real 2.x dump, and fixed: the five patches that alter tables
  (`KeyDesignCategoriesByPair`, `KeyDesignsToFontCatalogue`, `MergeFaceLogIntoGenerationLog`,
  `MoveAiNotesToOneTable`, `DropRemovedModuleLeftovers`) now run outside the transaction Magento wraps a data patch
  in, because Magento refuses DDL inside one and the table drop also waited forever on a lock the same transaction
  held. `CopyGiftwrapFonts` copies `is_active` only where the old table has it; a 2.x site does not, and the new
  table's default stands in.
- `PurgeMadeAssets`: every file the shop made for itself - previews, tiles at a size, thumbnails, feed pictures, font previews, downloads - is deleted on the way to 3.0, files and rows, and after it the resized copies the kind backfill could give no kind. Any other row with no kind is left alone: on a shop this was not written against it could be a customer's own upload, and the backfill names those directories in the log. Given files (uploads, design tiles, fonts, AI pictures, face cut-outs, print files) stay; a print file keeps its own expiry, and nothing goes to the printer until the upgrade is done. Everything taken is made again the moment anything asks; the deploy runs the design preview and feed regeneration straight after so no customer waits for it. Runs before the font previews are rendered.

## Removed

- `ben_giftwrap_font`, `ben_giftwrap_face_log`, `ben_designer_asset_quality` and `ben_giftwrap_face_summary`,
  each dropped by the patch that moved its rows somewhere better. All three of the latter were taken out of their
  modules' declared schema and whitelists first, so the schema step would not drop them before the patch could
  read them.
- The face service's API key and queue wait settings, the offshore postcode lists, the three delivery sentences,
  the print strip promotion's four settings, the second free delivery threshold in giftwrap and in promotion, the
  Gallery tool's Allow Multiple Uploads, and the QualityScore tool's rows on the products it was tried on.
- The tables and version rows of Amazon, Dotdigital, Klarna, Vertex, Yotpo and several Magento modules that were
  taken out with composer years ago and never uninstalled themselves.

## Under the hood

- Installing the giftwrap designer on a store that has the product and none of the tools is a fresh-install seed
  rather than a conversion of 2.x data, so it moved to Ben_DesignerGiftwrap, which owns those tools, and runs
  ahead of everything here. It names its old class, so a store that has already run it does not run it again.
- Four patches that only ever tidied up data made during 3.0 development are gone: the design type briefly
  called art, the AI tool's first heading, the sticker blade offset option, and the hash backfill in Ben_Ai.
  None of the three live sites can hold the data they looked for - the tables and tools they name were all
  written for 3.0 - so they would have run over nothing.
- Every one-off 2.10 to 3.0 migration lives in this one module, so the modules that own the tables are left
  holding only the code a running shop needs. **The module is temporary** and is deleted once giftwrap.co.uk,
  pics2posters.co.uk and festive have all run 3.0 and their numbers have been checked. Nothing may depend on it.
- Each patch keeps the class basename it had in the module it came from and names that module's old FQCN in
  `getAliases()`, so a site that already ran it — dev, staging — sees it as applied and it never runs twice.
  `RemoveFreeShippingThresholdConfig` existed in both Ben_Giftwrap and Ben_Promotion and a module has one
  namespace, so those two are `RemoveGiftwrapFreeShippingThresholdConfig` and
  `RemovePromotionFreeShippingThresholdConfig`, each aliasing its own original.
- `Ben\Migration\Model\Gate` is the question every migration asks before it touches anything: `hasModule()`,
  `hasTable()`, `hasColumn()`. Each is a plain look at the database rather than a guess from a module list,
  because a module can be enabled on a site that never ran its schema. Nothing throws: a migration that cannot
  run is a migration with nothing to migrate, logged once by name.
- `getDependencies()` is kept across the whole chain, so the patches still run in the order the data needs —
  most visibly, the config purge waits for every patch that still has a config path to carry, and the leftover
  table drop waits for the purge, which reads the same removed-module list.
- The two unattended removals, `PurgeRedundantConfig` and `DropRemovedModuleLeftovers`, write the whole list of
  what they are about to do to the log before doing any of it, because a row or table removed unattended has no
  other record. Neither refusal — pinned config paths, an unwritable dump directory — fails the upgrade.
