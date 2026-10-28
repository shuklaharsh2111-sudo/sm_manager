SME Creative Review Status Patch

Installation
1. Back up your current project folder: C:\xampp\htdocs\usp
2. Extract this ZIP into C:\xampp\htdocs\ (preserve the included usp\ folder structure).
3. Allow these files to be overwritten when prompted; the new stylesheet will be added:
   - usp\sme\dashboard.php
   - usp\admin\admincreatives.php
   - usp\assets\js\admincreatives.js
   - usp\assets\css\admincreatives.css
   - usp\assets\css\smecreative-status.css
4. In the SME dashboard, open "My Creative Reviews". Creatives are now displayed in separate Pending, Approved, Rejected, Redo Required, and Drafts sections. Each section shows its own creatives and a message when it is empty.
5. In the admin Creative Studio, the Reject button updates a creative to rejected; Approve and Revision continue to work.
6. Hard-refresh the browser (Ctrl+F5) so the updated JavaScript and CSS are loaded.

Notes
- The dashboard query uses creatives.user_id = the currently signed-in SME's users.id. The existing add_creative.php saves the submitting SME's ID in that field.
- No database migration is required: the supplied schema already includes status values approved, rejected, and revision, plus revision_reason.
- PHP syntax and JavaScript syntax checks passed for the earlier patch. This update adds separate status grouping; a live database/browser test could not be run in this environment.
