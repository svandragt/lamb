`bin/upgrade` now prints `sudo -u '#<uid>' bin/lamb migrate` when the database owner has no user name, as in a Docker bind mount, instead of the failing `sudo -u UNKNOWN bin/lamb migrate`.
