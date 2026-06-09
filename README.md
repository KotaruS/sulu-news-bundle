# Simple News for Sulu CMS

PHP 8.3+, Sulu 2.6, Symfony 7.4+

![GitHub release](https://flat.badgen.net/github/release/KotaruS/sulu-news-bundle)
![Supports Sulu 2.6 or later](https://flat.badgen.net/badge/Sulu/2.6/52B5C9?icon=php)

## Installation

This bundle requires PHP 8.3 or later.

1. Open a command console, enter your project directory and run:

  ```console
  composer require kotaru/sulu-news-bundle
  ```

  
  You'll also need to add the bundle in your `config/bundles.php` file:

  ```php
  return [
      //...
      Kotaru\Bundle\SuluNewsBundle\SuluNewsBundle::class => ['all' => true],
  ];
  ```

2. Register the new routes by adding the following to your `config/routes/sulu_news_admin.yaml`: 

  ```yaml
  sulu_news_api:
      resource: "@SuluNewsBundle/Resources/config/routing_api.yaml"
      prefix: /admin/api
  ```
3. Add following config to your `config/image-formats.xml`: 

  ```xml
  <format key="article-header">
    <meta>
        <title lang="cs">Úvodní obrázek článku (2100x900)</title>
        <title lang="en">Article Header (2100x900)</title>
    </meta>

    <scale x="2100" y="900" mode="outbound" />
  </format>

  ```
4. Create a template in your `templates/news/index.html.twig`: 
  
3. **Enjoy the new features of your Sulu installation!**


## Extras

- `/admin/api/check/news/{uuid}` endpoint for checking user permissions to edit this news page.


## Twig extensions

### Functions

#### `get_related_news`

**Arguments:**

- `uuid`: page uuid
- `locale`: locale to resolve news for
- `limit`: how many news to load

Returns a list of related news with given locale

**Usage:**

```twig
{{ get_related_news('4afdde53-917b-40d2-aed7-3d74c485c39c','en',3) }}
Result: 
[news1,news2,news3]
```