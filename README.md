# Pimcore Backup and Sync Bundle

This plugin adds 3 commands to bin/console:

* `backup:backup` backups all necessary files and a database dump to a file
* `backup:restore` restores Pimcore status based on a previously saved backup
* `backup:sync` copy database and files between two Pimcore systems (e.g. to sync development from live system)

## Backup
It is important to backup files and database at nearly the same point in time because some database entries refer to files (e.g. versions) and some files refer to database columns (e.g. data object fields). Thus the backup of the files and the database are done in parallel.

Restorable data and logs do not get included in the dump to keep file sizes small.

By default, the backup file gets created in `/tmp` folder. To change this, please read about [Storage configuration](#storage-configuration).

### Storage configuration
Multiple storage providers are supported (local storage, FTP, S3, Azure etc.). To configure the location of backups you have to add a service definition to your `app/config/services.yml`

Example for local storage (path should be adjusted):
```yaml
services:
    blackbit.backup.adapter:
        class: League\Flysystem\Local\LocalFilesystemAdapter
        arguments:
            $location: '/tmp'
```

Example for AWS S3 storage:
```yaml
services:
    blackbit.backup.s3Client:
        class: Aws\S3\S3Client
        arguments:
            - {
                credentials:
                    key: your-key
                    secret: your-secret
                region: your-region
                version: latest
            }
      
    blackbit.backup.adapter:
        class: League\Flysystem\AwsS3V3\AwsS3V3Adapter
        arguments: ['@blackbit.backup.s3Client', 'your-bucket-name', 'optional/path/prefix']
```

Please also see [the documentation for other configuration options](https://flysystem.thephpleague.com/docs/adapter/aws-s3/).

To use other storage providers, please add the [flysystem adapter](https://flysystem.thephpleague.com/docs/#officially-supported-adapters) to your composer.json and configure `blackbit.backup.adapter` as documented. If you need help, feel free to contact [help@blackbit.de](mailto:help@blackbit.de).

#### Compatibility with Flysystem 1
You can still use this bundle with Flysystem 1 if you cannot use Flysystem 2 because of another dependency which needs Flysystem 1. In this case please add the following configuration in your `app/config/services.yml`:
```yaml
services:
    blackbit.backup.adapter:
        class: League\Flysystem\Adapter\Local
        arguments: ['/tmp']
```
Or adjust this for other Flysystem adapters.

### Backup execution
Backups should be saved regularly. When you call `bin/console backup:backup` without parameter the backup archive file gets named `backup_pimcore-YYYYMMDDhhmm.tar.gz` (YYYYMMDDhhmm gets replaced by current date and time). Alternatively you can set the name yourself by providing an argument like `bin/console backup:backup backup.tar.gz`. This plugin does not care about deleting old backups, so keep an eye on available disk space.

If you only want to backup th database, you can use the option `--only-database`. The advantage in comparison to a default `mysqldump` command execution is that certain unimportant, automatically recreatable or temporary database tables are not written to the dump which results in smaller file size without any disadvantages.

If you do not need versioning data, you can use the option `--skip-versions`.
If you do not need assets, you can use the option `--skip-assets`.

### Restore
Backups can be restored by executing `bin/console backup:restore <filename>`. It uses the same storage configuration as described above.

When you try to restore the database and get the error `ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is enabled (you *might* want to use the less safe log_bin_trust_function_creators variable)` please enable `log_bin_trust_function_creators` in the MySQL settings. This message appears when recreating triggers, functions etc. (even although the bundle removes the definers from the database dump).

### Sync between Pimcore systems
When you want to sync a Pimcore system with another Pimcore system you can use the `backup:sync` command (to be executed from the target system). You have to provide an SSH handle to the source system and the Pimcore root directory path of the remote Pimcore system and it will sync the database, the files while keeping the current configuration in `/app/config`.

Example call: `bin/console backup:sync user@hostname /var/www/html`

For this to work source and target system have to have this bundle installed.

### Trigger backup / sync from Pimcore backend
In combination with the [Process Manager bundle](https://github.com/elements-at/ProcessManager) you are able to trigger a backup, restore or sync with another Pimcore system directly from the Pimcore backend.

### Customization
If you want to execute project-specific things, you can use this with an event listener. The following events get dispatched:

* `backup.restore.stepFinished` - gets dispatched after every step during `backup:restore` and `backup:sync` command
* `backup.restore.finished` - gets dispatched after complete `backup:restore` and `backup:sync` commands are finished

## About Blackbit

Beside of this Pimcore plugin Blackbit also offers [other bundles, individual development, consulting and hosting for your Pimcore project](https://pimcore.com/en/partners/find-a-solution-partner/blackbit_p79).