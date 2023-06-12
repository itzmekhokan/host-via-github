# host-via-github
Host or Publish your WordPress plugin from GitHub and get update notification on every release made via Github.

<!-- GETTING STARTED -->
## Getting Started

To add this feature for your plugin only you have to follow below steps -

1. Include `config.php` file into your plugin 
```php
   // include github configuration file
    include 'config.php';
   ```
2. After this include `class-host-via-github.php` file into your plugin.
```php
   // include Host via GitHub Class file
    include 'class-host-via-github.php';
   ```
3. And then initialize the above class with define config constants
```php
   // include Host via GitHub Class file
    new Host_Via_GitHub( array(
        'pluginFile' 	=> HVG_PLUGIN_FILE,
        'pluginVersion' => HVG_VERSION,
        'userName' 		=> HVG_GITHUB_USERNAME,
        'repositoryName'=> HVG_GITHUB_REPOSITORY_NAME,
        'organisation'  => HVG_GITHUB_ORGANISATION,
        'accessToken' 	=> HVG_GITHUB_ACCESSTOKEN,
        'autoUpdate' 	=> HVG_AUTO_UPDATE,
        'preReleaseTag' => HVG_PRE_RELEASE_VERSION_TAG,
    ) );
   ```
4. Thats it, All Done. Have fun.