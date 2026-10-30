SME Settings Page Patch

What you get
In the SME panel, the Settings link in the sidebar now opens a real page with three sections:
  Profile      - change profile photo (upload / remove), full name, email, contact number.
                 Also shows role, department, reporting manager and brand (read-only).
  Password     - change password: current + new + confirm, show/hide, strength meter,
                 live checklist. Wrong-password attempts are limited (5 tries, then a 5 minute pause).
  Preferences  - notification switches (creative approved / rejected / redo requested /
                 meeting reminders) and clock format (24-hour or 12-hour).
Saving a name or photo updates the sidebar and the greeting immediately, no reload.

Installation
1. Back up your current project folder: C:\xampp\htdocs\usp
2. Extract this ZIP into C:\xampp\htdocs\ (preserve the included usp\ folder structure).
3. Allow these existing files to be overwritten when prompted:
   - usp\sme\sme.php
   - usp\assets\js\sme.js
   - usp\assets\css\sme.css
   New files added:
   - usp\sme\settings.php
   - usp\assets\css\smesettings.css
   - usp\assets\js\smesettings.js
   - usp\sme_settings_migration.sql   (optional, see below)
4. Hard-refresh the browser (Ctrl+F5) so the updated JavaScript and CSS are loaded.
5. Sign in as an SME user and click "Settings" in the sidebar.

Database
- Name, email, contact, profile photo and password use the existing `users` table
  (columns fullname, email, contact, prof_image, password). No change needed.
- Preferences are stored in a new table `user_settings`. The page creates it
  automatically the first time Settings is opened. If your MySQL user is not
  allowed to create tables, import usp\sme_settings_migration.sql in phpMyAdmin instead.

What changed in the three overwritten files (in case you have your own edits to merge)
- sme.php : also selects users.prof_image; shows the photo in the sidebar avatar (falls back
            to the first letter); enables the 'settings' route in $allowedPages; loads
            smesettings.css / smesettings.js on the settings page; adds
            data-clock-format to <body>; adds ids sidebarAvatar, sidebarName, headerName.
- sme.js  : the live clock honours data-clock-format (12-hour or 24-hour).
- sme.css : .profile-avatar can now hold an image (overflow hidden + img sizing).

Notes
- Profile photos are saved in usp\uploads\ (JPG, PNG, WEBP or GIF, up to 5 MB). Make sure that
  folder is writable. When a photo is replaced or removed, the old file is deleted only if it
  was created by an upload and nothing else uses it, so the sample images are never removed.
- Email and contact number must be unique, because they are also used to sign in.
- The notification switches are saved per user, but this project has no email/notification
  sender yet, so they do not trigger messages until one is added.
- Opening usp\sme\settings.php directly in the browser redirects to sme.php?page=settings.
