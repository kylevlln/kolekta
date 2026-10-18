# Kolekta

A barangay waste management web app: collection scheduling, truck dispatch alerts, and missed-pickup reporting.

## Tech stack

- PHP 8, MySQL/MariaDB, Apache (XAMPP)
- Vanilla JavaScript, no framework, no build step
- PDO, sessions + CSRF tokens, bcrypt

## Getting started

1. Install [XAMPP](https://www.apachefriends.org/) (default path `C:\xampp`).
2. Place this project in `C:\xampp\htdocs\kolekta`.
3. Double-click `run.bat` — it starts MySQL and Apache, sets up the database, and opens the app.
4. Visit `http://localhost/kolekta`.

Manual setup: start Apache and MySQL, then run `php setup-db.php`.

## Project structure

```
api/        JSON endpoints
assets/     CSS and vanilla JS
db/         Schema and seed data (kolekta.sql)
inc/        Shared page helpers
legal/      Privacy, Cookie, and Terms pages
uploads/    Report photo uploads
```

## License

Personal project. Feel free to use and learn from it.
EOF