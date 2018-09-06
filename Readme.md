# Pimcore Backup

This plugin adds 2 commands to bin/console:
* `app:backup` backups all necessary files and a database dump to a file
* `app:restore` restores Pimcore status based on a previously saved backup

## Backup
It is important to backup files and database at nearly the same point in time because some database entries refer to files (e.g. versions) and some files refer to database columns (e.g. data object fields). Thus the backup of the files and the database are done in parallel.

### Storage configuration
Multiple storage providers are supported (local storage, FTP, S3, Azure, Dropbox etc.). To configure the location of backups you have to add a service definition to your `app/config/services.yml`
Example for local storage (path should be adjusted):
```yaml
services:
    blackbit.backup.adapter:
        class: League\Flysystem\Adapter\Local
        arguments: ['/tmp']
```
        
Example for AWS S3 storage:
```yaml
services:
    blackbit.backup.s3Client:
        class: Aws\S3\S3Client
        arguments:
            credentials:
                key: your-key
                secret: your-secret
            region: your-region
            version: latest
      
    blackbit.backup.adapter:
        class: League\Flysystem\AwsS3v3\AwsS3Adapter
        arguments: ['@blackbit.backup.s3Client', 'your-bucket-name', 'optional/path/prefix']
```

### Backup execution
Backups should be saved regularly. When you call `bin/console app:backup` without parameter the backup archive gets named `backup_pimcore-YYYYMMDDhhmm.tar.gz` (YYYYMMDDhhmm gets replaced by current date and time). Alternatively you can set the name yourself by providing an argument like `bin/console app:backup backup.tar.gz`. This plugin does not care about deleting old backups, so keep an eye on available disk space.

### Restore
Backups can be restored by executing `bin/console app:restore <filename>`. It uses the same storage configuration as described above.