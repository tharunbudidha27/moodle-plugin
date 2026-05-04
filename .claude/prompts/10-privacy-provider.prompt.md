# Prompt — Generate Privacy Provider

The GDPR / privacy provider implements per-asset deletion with retry. Local soft-delete is unconditional and immediate; the FastPix-side delete is best-effort with a retry queue picked up by the `retry_gdpr_delete` scheduled task. **Never** issue bulk deletes — every asset is its own DELETE.

Variables to fill in: none.

---

```
You are @security-compliance for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §16 (privacy provider).
- The architecture uses per-asset DELETE pattern with retry.

TASK: Generate `local/fastpix/classes/privacy/provider.php`.

REQUIREMENTS:
1. Namespace: `local_fastpix\privacy`. Class: `provider`.
   Implements: \core_privacy\local\metadata\provider,
               \core_privacy\local\request\plugin\provider,
               \core_privacy\local\request\core_userlist_provider.

2. get_metadata: declares
   - local_fastpix_asset (owner_userid, title)
   - local_fastpix_upload_session (userid)
   - external location 'fastpix' (media_id, metadata)

3. delete_data_for_user($contextlist):
   For each asset owned by user:
     a. UPDATE: deleted_at=now, gdpr_delete_pending_at=now, timemodified=now.
     b. try gateway->delete_media($fastpix_id);
        on success: set_field gdpr_delete_pending_at=null;
        on gateway_unavailable: leave pending; retry task picks up.
   DELETE all upload_session rows for user.

4. export_user_data: write asset rows + upload sessions to writer.

5. get_contexts_for_userid: return system context if user owns any rows in either table.

6. get_users_in_context, delete_data_for_users: standard implementations.

DO NOT:
- Bulk-delete via a single FastPix API call (per-asset only).
- Skip the local soft-delete on FastPix failure (compliance: local data gone immediately).
- Log raw user IDs in any error path.

OUTPUT: PHP file only.
```
