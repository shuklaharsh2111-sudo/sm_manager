Meetings scroll + Company Health patch

1. Upcoming Meetings: the list now scrolls inside its box (max ~520px) and the Add Meeting form
   scrolls too, so long lists / long links / many meetings no longer break the page layout.
2. "Company Comparison" is now "Company Health" (heading, messages, CSV name company-health.csv).
3. New last column "HEALTH" in the table:
     Healthy   = no delayed creatives and 70%+ of reviewed creatives approved
     At Risk   = some delayed creatives, or under 70% approved
     Critical  = 3+ delayed creatives, or under 40% approved
     No data   = company has no creatives yet
   (reviewed = approved + redo + rejected; delayed = pending/redo creatives past their date).
   Hover a badge to see its rule. The health column is also in the CSV export.

Install: extract into C:\xampp\htdocs\ and overwrite. NEW files: assets\css\smemeetings.css, assets\css\bhhealth.css
Overwrite: sme\dashboard.php, sme\bh.php, assets\js\bh.js
(Keep the earlier SOP patch files too.) Assets are version-stamped, so no hard refresh needed.
