---
title: Upgrading
---

# Upgrading

Full instructions for upgrading lamb to a newer version.

This is what I do every night:

```
git pull
git reset --hard $(git rev-parse --abbrev-ref --symbolic-full-name @{u})
composer install
```

This is all you need to upgrade. There is [more information about branches](https://github.com/svandragt/lamb/blob/main/BRANCHES) to be on.

## Explanation

The commands are a series of Git and Composer commands typically used in a development environment. Here's a breakdown of what each command does:

1. **`git pull`**:
   - This command fetches changes from the remote repository and merges them into the current branch. It is a combination of `git fetch` (which retrieves the latest changes from the remote) and `git merge` (which integrates those changes into your current branch).

2. **`git reset --hard $(git rev-parse --abbrev-ref --symbolic-full-name @{u})`**:
   - This command resets the current branch to the state of its upstream branch (the branch it is tracking on the remote).
   - `git rev-parse --abbrev-ref --symbolic-full-name @{u}` retrieves the name of the upstream branch for the current branch. The `@{u}` syntax refers to the upstream branch.
   - The `git reset --hard` command then resets the current branch to match the upstream branch exactly, discarding any local changes (both staged and unstaged). This means any uncommitted changes in your working directory will be lost.

3. **`composer install`**:
   - This command is used in PHP projects that use Composer as a dependency manager. It installs the dependencies listed in the `composer.json` file.
   - If the `composer.lock` file exists, it will install the exact versions of the dependencies specified in that file. If it doesn't exist, it will create one based on the versions of the dependencies that are installed.

### Summary

In summary, these commands are used to update a local Git repository to match the remote repository (discarding any local changes) and then install the necessary PHP dependencies for the project. This sequence is often used to ensure that the local development environment is in sync with the latest code and dependencies from the remote repository.
