# php-ebay-upload-images

### usage

```php
$images[] = file_get_contents('/tmp/img1.png');
$images[] = file_get_contents('/tmp/img2.png');
//$images[] = 'this should not work'; <-- test this so see how things fail

$EbayUploadImages = new upload_images([
                          'app-name'   => 'xxxxxxxx',
                          'cert-name'  => 'xxxxxxxx',
                          'dev-name'   => 'xxxxxxxx',
                          'siteid'     => 77,
                          'auth-token' => 'xxxxxxxx'
]);

$responses        = $EbayUploadImages->upload($images);
d($responses);
```

**notice:** `siteid` needs to be numbers, if you use letters you might get very strange SSL errors! 

### what you (should) get

![](https://i.imgur.com/6mNud71.png)

`state` should cover the result of _all_ uploads, if one failed, it will show you `FALSE` with an array key `error`. 
  
some more config keys you can use:

- comp-level (X-EBAY-API-COMPATIBILITY-LEVEL)
- ExtensionInDays ([see ebay docu](https://developer.ebay.com/devzone/xml/docs/reference/ebay/UploadSiteHostedPictures.html#Request.ExtensionInDays))

### You need help with the Ebay API?

**hire me:** `info@macropage.de`

### Follow me on Social Media

[Mastodon](https://social.vivaldi.net/@michabbb)  
[LinkedIn](https://www.linkedin.com/in/macropage/)  
[BlueSky](https://bsky.app/profile/michabbb.bsky.social)  
[X](https://x.com/michabbb)  


