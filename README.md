# Filament

Keeps track of which spools of 3D printing filament are in the cabinet:
brand, material, colour, how much is left, and a photo for the ones that
have no name printed on them. Runs on ordinary PHP web hosting with MySQL.

Anyone can look. Adding and changing needs a password.

## What it does

- Search across everything at once: brand, material, colour name, colour
  code, notes, where you bought it, and the status
- Filter by material, brand, status or how you got it, and sort the result
- One record per physical spool, so you can tell two black PLAs apart
- Sealed / opened / empty, with a rough percentage for the opened ones
- Notes per spool, and the notes are searchable
- Photos straight from the phone camera, up to six per spool
- A colour dot per spool, so you can scan the cabinet by eye
- Records whether you bought a spool, were given it, or won it
- Vendor, price and date for the ones you bought
- Totals at the top: spools, sealed, opened, and how much filament is left
- Installs as an app on your phone and still opens without signal

## Installing on mijndomein.nl

### 1. Create a database

Plesk → **Databases** → *Add database*. Note the name, user and password.

### 2. Check the PHP version

Plesk → **Websites & domains** → *PHP settings*. Set the version to **8.4**
(or 8.3 if 8.4 is not there yet). Below 8.1 will not work.

Under the same settings, make sure **gd** is switched on. Without it the app
still works, but photos are stored at full camera size instead of being
scaled down.

### 3. Upload

Put the **contents** of the `httpdocs/` folder into the `httpdocs` folder on
the server, through Plesk File Manager or with SFTP (FileZilla).

Do you have a local `inc/config.local.php`? Do **not** upload it — those are
your test settings. The installer creates its own on the server.

The layout on the server becomes:

```
httpdocs/
├── index.php          ← the overview, open to everyone
├── filament.php       ← one spool
├── edit.php           ← add and change, password protected
├── login.php
├── logout.php
├── install.php        ← delete after step 4
├── upgrade.php        ← only needed when updating, delete after
├── manifest.json
├── sw.js
├── .htaccess
├── api/
│   └── remaining.php
├── assets/
│   ├── app.css
│   ├── app.js
│   └── icon-*.png
├── uploads/
│   └── .htaccess      ← blocks scripts in this folder, must come along
└── inc/
    ├── .htaccess      ← blocks direct access, must come along
    ├── config.php
    ├── db.php
    ├── auth.php
    ├── helpers.php
    ├── filament.php
    ├── images.php
    └── results.php
```

### 4. Let the uploads folder be written to

Plesk File Manager → right-click `httpdocs/uploads` → *Change permissions*,
and give the web server write permission. Without this, photos cannot be
saved. The installer tells you if it is not set.

### 5. Install

Go to `https://yourdomain.nl/install.php`. There you fill in:

- Database server (at mijndomein: `localhost`)
- Name of the database, user and password — from step 1
- A prefix for the table names, optional — see below
- An admin password of your own, at least 8 characters

**Prefix.** Leaving it empty is fine: the tables are then called `filament`
and `filament_photo`. If you share that one database with another site, fill
in something like `filament_`. The tables are then called `filament_filament`
and do not get in each other's way. The prefix also applies to the foreign
keys, because those names have to be unique across the whole database.

The installer first tests whether the connection works, writes the details to
`inc/config.local.php`, and creates the tables. The admin password is stored
as a hash, so it cannot be read back out of that file. You never have to edit
a file yourself.

### 6. Remove install.php

**Delete `install.php` from the server as soon as you are done.** While it is
still there, anyone who knows the address can open it.

### 7. Done

`https://yourdomain.nl` shows the collection. Adding filament goes through
the **+ Add spool** button, which asks for the password you chose.

### Putting it on your phone

The site is a PWA, so you can install it as an app. That only works over
https.

**Android (Chrome):** open the site, menu (three dots) → *Install app*. If
that option is missing it will say *Add to home screen*, which does the same.

**iPhone (Safari):** open the site, share button (square with an arrow) →
*Add to Home Screen*. This has to be done in Safari; Chrome on iOS cannot.

Once installed it opens without an address bar and with its own icon. A
service worker keeps the styling and the spools you last looked at, so the
app still opens when you are in the shed without signal. Adding a spool
needs a connection, of course.

## Photos

The **Take a photo** button opens the camera straight away on a phone.
**Choose from library** picks files you already have, several at once.

What gets stored is a scaled down JPEG of at most 1600 pixels on the long
edge, plus a small version for the overview. The EXIF data is dropped along
the way, which also removes the GPS tag your phone quietly puts in there.
Pictures taken sideways are turned the right way up.

The first photo is the cover, which is the one shown in the overview. You can
point at a different one with **Cover** on the edit page.

JPEG, PNG and WebP go in. Anything else is refused, and a file that only
pretends to be a picture is refused too.

## How much is left

A sealed spool counts as full, an empty one as nothing left, and only an
opened spool has a percentage you set yourself. Rough is fine — nobody weighs
these.

On a spool's own page there is a slider and three status buttons that save by
themselves, without going through the whole form. Sliding a sealed spool down
marks it as opened, because that is what you are telling it.

## Searching

Type several words and each one has to match somewhere, but not in the same
field. So `bambu matte black` finds the spool whose brand is Bambu Lab, whose
material is PLA Matte and whose colour is Matte Black.

Besides brand, material and colour it also searches your notes, the vendor,
the colour code, and the words `sealed`, `opened`, `empty`, `bought`, `gift`
and `giveaway`.

## Adjusting things

In `inc/config.php`:

```php
defined('MAX_PHOTOS_PER_ITEM') or define('MAX_PHOTOS_PER_ITEM', 6);  // photos per spool
defined('PHOTO_MAX_EDGE')      or define('PHOTO_MAX_EDGE', 1600);    // stored photo size
defined('PER_PAGE')            or define('PER_PAGE', 48);            // spools per page
```

The lists of materials and brands offered while typing are in
`inc/filament.php`. Both fields are free text, so a material you bought once
does not need a code change — the suggestions are only there to save typing.

## Updating to a newer version

Is the app already running and are you uploading new files? Upload them, then
go to `/upgrade.php` once and delete that file again.

It adds any missing columns and leaves your spools alone.

If you changed `app.css` or `app.js`, also raise `CACHE` at the top of
`sw.js`. The service worker refreshes files in the background, but a new
cache name makes sure everyone sees it straight away.

## Forgot the admin password

The password is stored as a hash, so it cannot be read back. Delete
`inc/config.local.php`, upload `install.php` again and run it once more with
the same database details. The tables that already exist are left as they
are, and you get to pick a new password.

## Requirements

- PHP 8.1 or higher (8.4 recommended), with PDO MySQL and mbstring
- GD recommended, for scaling photos down
- MySQL or MariaDB
