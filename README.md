# m2-migration

Every one-off 2.10 → 3.0 data migration, in one place, so the modules that own the tables are left holding only
the code a running shop needs.

## Features

- One `Setup/Patch/Data` class per migration, each keeping the class basename it had in the module it came from
  and naming that module's old FQCN in `getAliases()`, so a site that has already run it — dev, staging — sees it
  as applied and it never runs twice
- `Ben\Migration\Model\Gate`, the question every migration asks before it touches anything: `hasModule()`,
  `hasTable()`, `hasColumn()`, `hasConfig()` and `hasProduct()`. A no is one line in the log and an early return,
  never a failed upgrade — the three sites run different modules and a migration written for one of them meets
  tables that are simply not there on another
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

`MoveAssetNotesToOwners` is the one patch here that has to run before a schema change rather than after one.
`ben_asset` carried three documents for other modules -- the measurements, the note about the photograph and the
note about the faces in it -- and each now has a table of its own: `ben_asset_quality`, `ben_designer_asset_quality`
and `ben_giftwrap_face_summary`. Magento runs `db_schema` before data patches, so a release that dropped the
columns would take the values away before this ever ran. The order is therefore: **3.0 keeps the three columns**,
marked superseded in `Ben_Asset/etc/db_schema.xml`, with nothing reading or writing them; this patch copies every
non-null value across on upgrade; **the release after 3.0 drops the columns**, which is on the after-3.0 list. It
is INSERT IGNORE throughout, so a row the owning module has written since the upgrade is left alone and a second
run has nothing to do.

`ConvertGiftwrapOrderItems` is the one patch here that rewrites order items. Every giftwrap line the old
checkout took kept what the customer chose under a `giftwrap` key of its own, and a second set of classes in
Ben_Giftwrap existed only to read it back; those classes are deleted in this release, so the items are converted
to the designer's `designer_active_data` and `designer_type` instead, with the original options kept beside them
under `giftwrap_legacy`. The roll length is named by the hash of the configured row of that length, the way a new
item names it; the old checkout also sold lengths the picker no longer offers, and those items carry the length
alone, which is why `OrderProductPopulator` prints the length the order says when no configured row answers to
the hash. Old cart items are deliberately not converted: a cart is priced again on every load and the old
checkout's add-to-cart is already gone, so an old line in a basket at cutover is one to clear rather than carry.

`SetPromotionPasswordSecret` is the one patch here with no earlier life in another module: the priority access
password became a configuration field, and a shop already using the gate needs the word it was using written
into the field once or it turns everyone away. It writes `coming_soon/password/secret`, which is where the
field lives now, and depends on `Ben\ComingSoon\Setup\Patch\Data\CopyPromotionPassword` so that a shop which
had saved its own word under the old Promotion path keeps it and only an empty field is filled in. That is why
`Ben_ComingSoon` is in this module's sequence. It is a one-off like the rest and goes when the module does.
