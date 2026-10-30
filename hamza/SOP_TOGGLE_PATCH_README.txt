SME SOP Toggle + Auto-list Patch (v2)

What you get
  - "Your SOPs" is ONE big box with a Daily | Weekly | Monthly toggle (done/total on each tab).
  - SOPs the admin assigns (Users > Manage SOPs, or Companies) appear in the list of the SME
    assigned to that company. The list refreshes every 30 s and when you return to the tab.
    SOPs added in the last 24 h show a "New" badge.

Why v1 looked unstyled / toggle did nothing
  The browser kept using the OLD cached smedashboard.css/.js. v2 puts the SOP code in NEW files
  (smesop.css, smesop.js) and loads all dashboard assets with ?v=<file time>, so stale cache
  can no longer happen.

Install (back up C:\xampp\htdocs\usp first, then extract into C:\xampp\htdocs\)
  NEW files:   usp\assets\css\smesop.css, usp\assets\js\smesop.js
  Overwrite:   usp\sme\dashboard.php, usp\sme\sop_api.php, usp\sme\sop_helpers.php,
               usp\assets\css\smedashboard.css, usp\assets\js\smedashboard.js
  ALL 7 files are needed together. Then press Ctrl+F5 once. No database change.

Note: an SOP shows only for the SME assigned to that SOP's company (Admin > Companies > Assign SME).
