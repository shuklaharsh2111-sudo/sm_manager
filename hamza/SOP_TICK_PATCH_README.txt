SME SOP Tick + Saved Patch

What you get
  Dashboard > "Your SOPs": every SOP now has a tick box. Ticking it saves it
  straight away to the new "Saved" section (sidebar link "Saved", or the Saved
  button above the SOP boxes).
  - Daily SOP ticks refresh every day, weekly SOP ticks every week (Mon-Sun),
    monthly SOP ticks every month. Each box shows "done/total".
  - Unticking removes the tick for the current day/week/month only.
  - Saved page: full history, grouped by day, with Daily / Weekly / Monthly filters.

Installation
1. Back up C:\xampp\htdocs\usp
2. Extract this ZIP into C:\xampp\htdocs\ (keeps the usp\ folder structure).
   Overwrite when asked:
     usp\sme\dashboard.php, usp\sme\sme.php,
     usp\assets\css\smedashboard.css, usp\assets\js\smedashboard.js
   New files:
     usp\sme\saved.php, usp\sme\sop_api.php, usp\sme\sop_helpers.php,
     usp\assets\css\smesaved.css, usp\sme_sop_completions_migration.sql (optional)
3. Hard-refresh the browser (Ctrl+F5).

Database
- Table `sme_sop_completions` is created automatically on first use.
  If your MySQL user cannot CREATE tables, import usp\sme_sop_completions_migration.sql
  in phpMyAdmin (database creative_manager).

Notes
- Weeks run Monday to Sunday, using Indian Standard Time.
- Not tested live (no PHP/MySQL in the build environment); JS syntax was checked.
