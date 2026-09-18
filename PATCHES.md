# What each patch does

Every data patch Ben_Migration runs on the way from 2 to 3, in the order Magento runs them (a patch waits for the
ones it names; otherwise alphabetical). Each is gated, so it does nothing where the data it converts is absent, and
each can be run again. **alters tables** marks a patch that runs outside Magento's patch transaction because it
changes the schema by hand. A patch kept in another module is not listed here.

| # | Patch | What it does | Waits for |
|---|---|---|---|
| 1 | `ConvertGalleryFileTypesToFormats` | The Gallery tool's file types become a choice from the format registry rather than typed-in media types | `InstallGiftwrapDesigner` |
| 2 | `CopyGiftwrapColors` | The colour catalogue moves out of Ben_Giftwrap and into its own table, so the cards, the art range and the photo text can be printed from it without every one of them depending on the giftwrap module. | — |
| 3 | `CopyGiftwrapFonts` | The font catalogue moves out of Ben_Giftwrap and into its own table, so the art range, the AI artwork and the stickers can letter from it without every one of them depending on the giftwrap module. | — |
| 4 | `KeyDesignCategoriesByPair` | Puts the unique key on a design's categories, once the pairs it needs are actually unique. **Alters tables.** | — |
| 5 | `KeyToolOptionsToCatalogues` | The colour and font tools stop naming a PHP class and name a catalogue instead. | — |
| 6 | `MigrateDeviceActiveConfig` | Turns the old Device Enabled yes/no into the print workflow it always meant: yes was the print room app pulling files through the API, no was rendering them on order for download. Every scope that had its own answer keeps it under the new name, then the old row goes. A workflow already saved is the admin's later decision and is never overwritten, so running this twice moves nothing the second time | — |
| 7 | `MigrateExpiryRoleConfig` | Carries the saved expiry numbers over to the one number per role the group has now | — |
| 8 | `MigrateGalleryEndpointConfig` | Takes the Gallery group's leftovers out of Stores > Configuration > Designer > Gallery. | — |
| 9 | `MigrateQualityScoreConfig` | Brings saved Designer > Quality settings over to the points scoring. | — |
| 10 | `MoveAiNotesToOneTable` | Brings the two tables of AI notes about an upload into one and takes the originals away. **Alters tables.** | — |
| 11 | `MoveDesignerAttributeGroupBelowGeneral` | Sorts the "Designer" section directly under General on the product form in every attribute set, moving the groups that were there down one, and places every designer attribute in it. Stores that took the group at its old sort order need this, and a store whose attribute patches ran after the group patch is missing the later attributes from the group; fresh installs get both from the group patch. The attribute notes are refreshed too, so wording changes reach stores that already have the attributes | `AddDesignerAttributeGroup`, `AddDesignerVisibleInProductSwitcherAttribute` |
| 12 | `MoveFaceV2Config` | Moves the saved face service settings to Stores > Configuration > AI > Face V2, then drops what is left. | — |
| 13 | `MoveRegeneratedProductsOffPrinted` | Regenerating an order's print files used to mark them printed, with no date, only so the print room would not take them for new work. Regeneration now has its own flag and date, so those rows are moved onto it and printed goes back to meaning the print room took the file. | — |
| 14 | `MoveRoyalMailConfigToCarrierGroup` | Royal Mail now has a carrier group of its own, `shipping_api/carrier_rm`, alongside every other carrier, so the settings saved under the old `shipping_api/royal_mail` group move across at every scope that had its own answer and the old rows go. The client secret was held in the clear and is encrypted on the way over. A value already saved under the new name is the admin's later decision and is never overwritten, so running this twice moves nothing the second time | — |
| 15 | `RemoveAiDrawingLimitToolOptions` | Drops the prompt length and redraw limits a product carried, now that they are shop settings. | — |
| 16 | `RemoveDeliveryMessageConfig` | Drops the three fixed delivery sentences the banner used to pick between. | — |
| 17 | `RemoveGiftwrapFreeShippingThresholdConfig` | Moves the giftwrap copy of the free delivery threshold onto Magento's free shipping carrier, then drops it. | — |
| 18 | `RemoveGiftwrapSizeFreeShippingThresholdOption` | Drops the GiftwrapSize tool's freeShippingThreshold option and the value every product carried for it, carrying the number itself over to the shop's one threshold first. | — |
| 19 | `RemoveGlobalDesignerToolOptions` | Drops the tool options that are the shop's setting rather than a product's. | — |
| 20 | `RemoveGlobalGalleryToolOptions` | Moves the Gallery tool options that became shop settings into Stores > Configuration > Designer > Gallery, then drops them. | — |
| 21 | `RemoveOffshorePostcodesConfig` | Drops the postcode regions the retired DPD import file used to refuse. | — |
| 22 | `RemovePromotionFreeShippingThresholdConfig` | Moves the promotion copy of the free delivery threshold onto Magento's free shipping carrier, then drops it. | — |
| 23 | `RemoveQualityScoreProductTools` | Takes the QualityScore tool off the products that were given it while the scoring was being tried out. | — |
| 24 | `RemoveToolOptionRowTooltips` | Tooltips only belong on a tool's own heading (AddToolTooltipOptions), never on an individual option row - a frame finish, a mount size, a roll length. Those row-level tooltipTitle/tooltipDescription fields were added by hand through the admin rather than by a patch, so this strips them from every dynamic-row option's schema and from the rows already saved against a product, leaving everything else in the row alone. | — |
| 25 | `RenameDesignTypes` | Design types renamed for 3.0: tile became original, face became photo and generated became ai. | — |
| 26 | `RenameFaceoutProvider` | The face extraction service was called Faceout until 3.0 and is called Face V2 now, so the rows it wrote carry a name nothing answers to any more: the usage report counts them by provider and the grid filters on it. | — |
| 27 | `RenameProductGridPrintColumns` | The order products grid's printed columns were renamed, so the admins who had arranged that grid keep it. | — |
| 28 | `RenameVarcharSchemaTypeToString` | Tool option schemas name their value type as "string"; the older rows still say "varchar", a database word that leaked into the config. This rewrites every schema that carries it, and does nothing on a site already clean. | — |
| 29 | `RetireOldFeedWording` | Drops saved feed wording that still spells out roll lengths and paper width by hand. | — |
| 30 | `RetireStripPromoConfig` | Drops the four settings the print strip promotion used to be written into. | — |
| 31 | `SetGiftwrapDesignerType` | A product carrying giftwrap tools that predates designer types is a giftwrap product | `AddDesignerTypeAttribute` |
| 32 | `SetPhotoDesignerType` | Every product with designer tools that predates designer types is a photo product, unless one of its tools belongs to another type. A product carrying a giftwrap or a personalise tool is left untyped here rather than being called a photo it is not; the module that owns that type sets it | `AddDesignerTypeAttribute` |
| 33 | `SetPromotionPasswordSecret` | Brings the priority access gate's settings over from Ben_Promotion, where they used to live, and makes sure the shop ends up with a password. | — |
| 34 | `ShowFlowStatusesOnFront` | The processing statuses were created hidden from the storefront, which dropped every order sitting on them out of the customer order history, so make them visible on installs that already have them. | `AddOrderStatuses` |
| 35 | `SplitCopyrightYear` | The first legal line used to carry the copyright with a year typed into it, which went stale every January. The holder moves to its own field and the year is printed from the clock; a line that did not end in a sign and a year is left as it is. The retired Trustwave url rows go at the same time | — |
| 36 | `AddPlainTextColors` | Black and white go into the colour catalogue. | `CopyGiftwrapColors` |
| 37 | `BackfillExpiryRoles` | Gives every asset already in the table the role it would have been created with | `MigrateExpiryRoleConfig` |
| 38 | `DisableRetiredFonts` | The fonts the designer used to hide with a hard coded exclusion list are switched off with the new Enabled flag. Every other font keeps the schema default of enabled, and nothing else about a row is touched. | `CopyGiftwrapFonts` |
| 39 | `KeyDesignsToColorCatalogue` | Moves a design's default colour off the old giftwrap colour table and onto the colour catalogue for good. **Alters tables.** | `CopyGiftwrapColors` |
| 40 | `KeyPersonalDesigns` | Marks the art designs already on the shop as personal and gives each one a key. | `RenameDesignTypes` |
| 41 | `MergeFaceLogIntoGenerationLog` | Brings the face service's own log into the generation log and takes the second table away. **Alters tables.** | `RenameFaceoutProvider` |
| 42 | `BackfillAssetKinds` | Gives every asset already in the table the kind whatever made it would name today | `BackfillExpiryRoles`, `RenameFaceoutProvider` |
| 43 | `ConvertGiftwrapOrderItems` | Rewrites every giftwrap order item the old checkout took into the shape the designer writes. | `InstallGiftwrapDesigner`, `KeyPersonalDesigns`, `RenameDesignTypes`, `SetGiftwrapDesignerType` |
| 44 | `KeepTopTwentyFonts` | The font range is cut to the twenty fonts customers actually order, everything else switched off. | `DisableRetiredFonts` |
| 45 | `RecolourAssetKinds` | Runs the two asset backfills a second time. **This patch is not tidying up: without it the roles a kind settles are never written at all, on any site.** | `BackfillAssetKinds`, `BackfillExpiryRoles` |
| 46 | `RepointDesignsToActiveFonts` | The font range was cut to the twenty fonts orders actually name, so the designs still opening in one of the fonts that went are moved to Pincher-Brothers, the one most of them already use. | `KeepTopTwentyFonts` |
| 47 | `KeyDesignsToFontCatalogue` | Moves a design's default font off the old giftwrap font table and onto the font catalogue for good. **Alters tables.** | `CopyGiftwrapFonts`, `RepointDesignsToActiveFonts` |
| 48 | `PurgeMadeAssets` | Takes away every file the shop made for itself, on the way from 2 to 3. | `RecolourAssetKinds` |
| 49 | `PurgeRedundantConfig` | Removes the saved settings 3.0 no longer reads, those of modules that are no longer installed, and the ones that only repeat what their scope inherits. | `MigrateDeviceActiveConfig`, `MigrateExpiryRoleConfig`, `MigrateGalleryEndpointConfig`, `MigrateQualityScoreConfig`, `Config`, `MoveRoyalMailConfigToCarrierGroup`, `RemoveDeliveryMessageConfig`, `RemoveGiftwrapFreeShippingThresholdConfig`, `RemoveGiftwrapSizeFreeShippingThresholdOption`, `RemoveGlobalGalleryToolOptions`, `RemoveOffshorePostcodesConfig`, `RemovePromotionFreeShippingThresholdConfig`, `RepointDesignsToActiveFonts`, `RetireStripPromoConfig`, `SetPromotionPasswordSecret`, `SplitCopyrightYear` |
| 50 | `DropRemovedModuleLeftovers` | Drops the tables and setup_module rows left by extensions this shop no longer runs. **Alters tables.** | `PurgeRedundantConfig` |
| 51 | `RenderFontPreviews` | The font samples stop being artwork somebody drew in Illustrator and become pictures the shop draws from the font file itself. | `KeepTopTwentyFonts`, `PurgeMadeAssets` |
