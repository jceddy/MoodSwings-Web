# database

MySQL schema for MoodSwings-Web.

## Setup

Create a database and load the schema:

```sh
mysql -u root -p -e "CREATE DATABASE moodswings CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p moodswings < schema.sql
```

## Layout

- `schema.sql` — Base table definitions. Extend this file (or add new versioned
  files here) as the game/simulator's data model grows.
