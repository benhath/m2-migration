# m2-migration

Every one-off 2.10 → 3.0 data migration, in one place, so the modules that own the tables are left holding only
the code a running shop needs.

## Features

- One `Setup/Patch/Data` class per migration, each keeping the class basename it had in the module it came from
  and naming that module's old FQCN in `getAliases()`, so a site that has already run it — dev, staging — sees it
  as applied and it never runs twice
- `Ben\Migration\Model\Gate`, the question every migration asks before it touches anything: `hasModule()`,
  `hasTable()` and `hasColumn()`. A no is one line in the log and an early return, never a failed upgrade — the
  three sites run different modules and a migration written for one of them meets tables that are simply not
  there on another
- Dependencies between the migrations are kept, so the chain still runs in the order it was written in

## Requirements

- Magento 2.4.9, PHP 8.4
- Depends on every module whose tables or config it touches: Ben_Ai, Ben_Asset, Ben_Designer,
  Ben_DesignerGiftwrap, Ben_DesignerPhoto, Ben_Font, Ben_Footer, Ben_Giftwrap, Ben_OrderFlow, Ben_Product,
  Ben_Promotion, Ben_Shipping

## Installation

```
composer require benhath/m2-migration
bin/magento setup:upgrade
```

## Configuration

None.

## Admin

None.

## Notes

**This module is temporary.** It is deleted once giftwrap.co.uk, pics2posters.co.uk and festive have all run
3.0 and their numbers have been checked against the post-upgrade list in the launch audit. Nothing may depend
on it, and nothing new belongs here that a running shop will need again.

Two classes were renamed on the way in, because `RemoveFreeShippingThresholdConfig` was the name of a patch in
both Ben_Giftwrap and Ben_Promotion and a module has one namespace: they are
`RemoveGiftwrapFreeShippingThresholdConfig` and `RemovePromotionFreeShippingThresholdConfig`, each aliasing its
own original FQCN.

`MergeFaceLogIntoGenerationLog` and `MoveAiNotesToOneTable` are the two patches that take a table away rather
than fill one in, and both drop it themselves. `db_schema` runs before data patches, so a table the declarative
schema still knew about would be dropped before the patch could read it; instead the three old tables are
declared no longer **and** taken out of their modules' `db_schema_whitelist.json`, the way `ben_giftwrap_font`
was handled, which leaves them standing for the patch.

`MergeFaceLogIntoGenerationLog` puts `ben_giftwrap_face_log` into `ben_ai_generation`, which now has the columns
it was keeping on the side. A scan was written down twice, so its face log row is matched onto its generation --
by the photograph, reached through the normalised copy that was actually sent, with the kinds agreeing, within
five minutes, each generation claimed once -- and a row that matches nothing is inserted as a generation of its
own, because the shop still made that call. A generation this patch has already described has a `kind`, which is
what stops a second run claiming it again. The two keep-warm flags go too: the log answers both questions now.

`KeyDesignCategoriesByPair` is `ben_giftwrap_design_category`'s unique key on the design/category pair, handled
the way `ben_giftwrap_font`'s key was. The pair has always been the real key and the admin save has always
written it as though it were, but declaring it in `db_schema.xml` would have put it on before any data patch
could run, and one duplicate pair left by an interrupted save on a live database aborts `setup:upgrade` with the
schema half applied. The declaration is therefore out of Ben_Giftwrap for 3.0 - the schema file says so where it
used to be - and this patch removes the duplicates first, keeping the lowest id of each pair and naming what it
removed in the log, then adds the key itself under the exact name `ResourceConnection::getIdxName()` gives it,
which is the name declarative schema generates. The declaration goes back into `db_schema.xml` in the release
after 3.0 and finds its key already standing. The NOT NULL columns and the two foreign keys on that table are
still declared and still run before any patch, so the orphan and NULL checks in the launch audit are run against
each live database immediately before the deploy.

`PurgeRedundantConfig` and `DropRemovedModuleLeftovers` are the two patches here that remove something nobody
asked them to, unattended, inside `setup:upgrade` on a live database. Both write the whole list of what they are
about to do to the log before they do any of it, because a row or a table removed unattended has no other record.
The purge never touches a path under `payment/`, `carriers/` or `web/secure/` whatever the audit says about it:
a payment or carrier setting removed in the release window is a shop that stops taking money and a secure base
URL removed is a shop served over plain HTTP, and those are worth more than a tidy `core_config_data`. Pinned
rows the audit would have removed are reported instead, for a person to remove afterwards. The leftover drop
writes a test file into `backups/work/removed-modules/<date>` first and, if it cannot, drops nothing at all and
says so: a table dropped with no dump behind it is the one thing here that cannot be undone. Neither refusal
fails the upgrade.

`RemoveOffshorePostcodesConfig` drops every `shipping_api/carrier_*/offshore_postcodes` row at every scope. Only
DPD ever refused a postcode of its own and it left with 3.0, so the field is gone from the shipping section and
nothing reads a saved row; each row removed is logged with its path and scope, and the run says how many went.
Nothing is carried over, because the shop refuses nothing by postcode now.

`MoveAiNotesToOneTable` puts `ben_designer_asset_quality` and `ben_giftwrap_face_summary` into Ben_Ai's
`ben_ai_asset_note`, one table with the purpose saying which module wrote a note, resolving each note's
`generation_hash` to the generation's own id on the way across. It is INSERT IGNORE throughout, so a note the
owning module has written since the upgrade is left alone and a second run has nothing to do.

`ConvertGiftwrapOrderItems` is the one patch here that rewrites order items. Every giftwrap line the old
checkout took kept what the customer chose under a `giftwrap` key of its own, and a second set of classes in
Ben_Giftwrap existed only to read it back; those classes are deleted in this release, so the items are converted
to the designer's `designer_active_data` and `designer_type` instead, with the original options kept beside them
under `giftwrap_legacy`. The roll length is named by the hash of the configured row of that length, the way a new
item names it; the old checkout also sold lengths the picker no longer offers, and those items carry the length
alone, which is why `OrderProductPopulator` prints the length the order says when no configured row answers to
the hash. Old cart items are deliberately not converted: a cart is priced again on every load and the old
checkout's add-to-cart is already gone, so an old line in a basket at cutover is one to clear rather than carry.

`SetPromotionPasswordSecret` carries the whole priority access group over: the switch, the two messages and the
password became configuration fields under Coming Soon, and a shop already using the gate needs what it was
using written into them once or it turns everyone away. It reads the old `promotion/password/*` rows in every
scope they were set in and writes `coming_soon/password/*`, the password as the ciphertext it already is, and
only where nothing has been typed there already, so an admin's own answer always wins. A shop with nothing to
carry over is given a random password and told so in the log; the word itself is never logged and is never a
literal here, because a password in the repository is no password at all. It used to be two patches, the copy
in Ben_ComingSoon and the fallback here; the removed class is named in `getAliases()`. `PurgeRedundantConfig`
names this patch, which is what keeps the old rows in place until they have been read. That is why
`Ben_ComingSoon` is in this module's sequence. It is a one-off like the rest and goes when the module does.
