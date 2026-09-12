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

`SetPromotionPasswordSecret` is the one patch here with no earlier life in another module: the priority access
password became a configuration field, and a shop already using the gate needs the word it was using written
into the field once or it turns everyone away. It is a one-off like the rest and goes when the module does.
