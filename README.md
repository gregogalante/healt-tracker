# Health Tracker

<img src="/icon.png" alt="Food Tracker Icon" width="100">

Applicazione per la gestione e tracciatura dei referti medici con funzionalità OCR creata in Vibe Coding.

## Installation

1. Copy the folder content on a PHP server.

2. Rename the `config.example.php` file to `config.php` and fill in your configuration.

3. Update the `pwa/manifest.json` file with your application details (correct start URL etc.).

4. Enjoy your health tracker!

## Protect data

Here there is an example of .htaccess to avoid direct access to data stored on `data/` folder and return a 403 error:

```apache
Deny from all

Options -Indexes

<Files ".htaccess">
    Require all denied
</Files>

ErrorDocument 403 "Access to this resource is forbidden."
```
