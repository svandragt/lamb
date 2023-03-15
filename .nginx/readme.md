# NGINX configuration

The data directory must be writable by php-fpm, something like:

```
sudo chown $USER:www-data data -R
sudo chmod g+w data -R
```

Reference documentation has been added the subfolders.
