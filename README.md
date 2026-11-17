# Etch HappyFiles Sync

A WordPress plugin that keeps **HappyFiles** media folders and **Etch** media collections in step,
both ways. Make, rename, nest, reorder or delete a folder in either place, or put a file in a folder,
and the other side follows.

Needs both HappyFiles (free or Pro) and Etch 1.7+ (the version that added media collections) active.
With either one off, the plugin does nothing.

## How it works

Both plugins store folders as a hierarchical taxonomy on attachments:

| | HappyFiles | Etch |
|---|---|---|
| Taxonomy | `happyfiles_category` | `etch_collection` (REST base `etch-collections`) |
| Order (term meta) | `happyfiles_position` | `position` |

Every folder gets a **twin** in the other taxonomy. The two are linked by the term meta `_ehs_twin`,
set on both. The plugin hooks core term events, which both plugins end up firing
(HappyFiles through its AJAX handlers, Etch through the core REST API):

| Change | Hook | Mirrored as |
|---|---|---|
| Folder created | `created_term` | twin created (parents first), or an unlinked same-name sibling adopted |
| Renamed / moved | `edited_term` | twin's name and parent updated |
| Reordered | `added_term_meta`, `updated_term_meta` | position copied to the other key |
| Deleted | `pre_delete_term` + `delete_term` | twin deleted. **Files are never deleted**, only unassigned |
| File put in / taken out of folders | `set_object_terms`, `deleted_term_relationships` | file's folders on the other side replaced to match |

A guard flag stops the plugin's own writes from being mirrored back.

When a folder is deleted, WordPress moves its subfolders up one level. The twin's subfolders move the
same way, so the trees stay equal. (The HappyFiles UI deletes subfolders one request at a time when
asked to, and each of those is mirrored.)

## Existing folders

Activating the plugin runs a **backfill**: every folder on either side without a twin gets one, and
every file ends up in the union of its folders from both sides. It is safe to run again:

```
wp etch-happyfiles-sync backfill
wp etch-happyfiles-sync status     # both trees, positions, file counts, twin ids
```

## Things to know

- Etch always allows a file in several collections. HappyFiles does too only with **Settings >
  Multiple folders per item** turned on. With it off, a file Etch puts in two collections still sits
  in both HappyFiles folders. Turning the setting on keeps the two UIs telling the same story.
- Folder colours (HappyFiles) have no Etch counterpart and are not synced.
- HappyFiles folders for other post types (`hf_cat_<post_type>`) are not touched.
- Deactivating leaves both trees and the `_ehs_twin` links in place. Reactivating runs the backfill.

## Tested

2026-11-17 on Local site `etch-kit-test` (Etch 1.7.1, HappyFiles Pro 1.9.1): core-function suite (17
checks), Etch's REST endpoints as the builder calls them, and HappyFiles' own AJAX handlers (create,
move file, rename, move folder, delete).

## Installing / releasing

Run `./build-zip.sh`. It zips the committed files (so commit first) into
`dist/etch-happyfiles-sync-<version>.zip`, with the plugin inside an `etch-happyfiles-sync/` folder as
WordPress expects. Upload that in **Plugins > Add New > Upload Plugin**. Each version's zip is also
attached to its GitHub release (`v<version>`). To release a new version, bump `Version:` in the plugin
header, commit, build, and `gh release create v<version> dist/<zip>`.
