# Path Syntax

One canonical syntax is used everywhere — arguments, output, logs, and errors.

## Remote paths

```text
<remote>:<bucket>/<path>
```

```text
do-spaces-nyc:my-data/videos/2026/movie.mp4
│               │       │
│               │       └── object key / prefix
│               └────────── bucket
└────────────────────────── rclone remote
```

Examples:

```text
do-spaces-nyc:my-data/report.pdf      # object
do-spaces-nyc:my-data/documents/      # directory / prefix
do-spaces-nyc:my-data                 # bucket root
```

A **trailing slash** marks an explicit directory/prefix. `do-spaces-nyc:my-data`
and `do-spaces-nyc:my-data/` are both accepted and normalized to the same bucket
root.

## Local paths

Use normal filesystem syntax:

```text
/home/user/downloads/report.pdf
./downloads/report.pdf
~/backups/
```

`~` is expanded to the user's home directory. Only the part before the **first**
`:` is read as a remote name, so a local path containing a colon needs the
explicit prefix:

```text
local:/path/with:colon/file.pdf
```

## Object keys with spaces

Object keys may legally contain spaces — quote the argument as usual:

```bash
storage copy "do-spaces-nyc:my-data/My Reports/Q3 final.pdf" /home/user/reports/
```

## Directory semantics

Copying a directory/prefix copies its **contents** into the destination while
preserving relative structure:

```text
source:  documents/a.txt         destination:  backup/a.txt
         documents/nested/b.txt                backup/nested/b.txt
```

The source directory name is **never** appended automatically. To nest it,
specify the destination explicitly:

```bash
storage copy src:bucket/documents/ dst:bucket/backup/documents/
```

These rules apply to [Copy](Copy), [Move](Move), [Rename](Rename), [Duplicate](Duplicate),
[Upload](Upload), and [Download](Download) alike.

## Related

- [Architecture](Architecture) — value object parsing the syntax
- [Exit-Codes](Exit-Codes) — what an unparseable path returns