# restoreall
A moodle CLI script to restore all courses in a folder to multiple categories

Drop this into your `/admin/cli` folder of your moodle or workplace installation, execute with php via the command line.


```bash
Options:
-h, --help                 Print out this help
-p, --path                 full path to source folder containing moodle backups
-r, --remove               Remove source backups after a sucessful restore
```

## Example
```bash
sudo -u www-data /usr/bin/php admin/cli/restoreall.php --path=/var/www/html/mysite/moodledata/backups --remove
```

## Licence
GPL 3
