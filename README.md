# LibreTranslateBundle

A bundle based on jefs42/libretranslate 

# Setup

Install libretranslate local

# Demo

```bash
symfony new TranslationDemo --webapp && cd TranslationDemo
composer req survos/libre-translate-bundle
bin/console make:controller AppController
```


dokku storage:mount  /mnt/volume-1/project-data/trans/data:/app/data
chown -R 32767:32767 /mnt/volume-1/project-data/trans

Open AppController.php and add 

```php
    #[Route('/{target}', name: 'app_homepage')]
    public function home(
        LibreTranslate $libreTranslate,
        CacheInterface $cache,
        string         $target = 'es'): Response
    {
        $url = 'https://saurav.tech/NewsAPI/top-headlines/category/health/in.json';
        $data = $cache->get(md5($url), fn(CacheItem $item) => json_decode(file_get_contents($url)));
        $translations = [];
        foreach ($data->articles as $idx => $article) {
            $translations[] = $cache->get(md5($article->title).$target,
                fn(CacheItem $cacheItem) => $libreTranslate->Translate($article->title, target: $target)
            );
        }

        return $this->render('app/index.html.twig', [
            'headlines' => $data,
            'translations' => $translations,
            'languages' => $libreTranslate->Languages()
        ]);
    }
```

Open app/index.html and replace the body with 

```twig
    {% for locale, lang in languages %}
        <a href="{{ path('app_homepage', {target: locale}) }}">
            {{ lang }}
        </a>
        |
    {% endfor %}
    <table>
        <tbody>
        {% for idx, headline in headlines.articles %}
        <tr>
            <td>
                <img style="width: 100px" src="{{ headline.urlToImage }}" />
            </td>
            <td>
                {{ headline.title }}
                <br />
                <i>
                    {{ translations[idx] }}
                </i>

            </td>



        </tr>
        {% endfor %}

        </tbody>
    </table>
```

Run the symfony server to see the results:

```bash
symfony server:start
```

# Generating code classes

Install java 11 or higher

Get CLI jar:
```bash
wget https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/6.6.0/openapi-generator-cli-6.6.0.jar -O openapi-generator-cli.jar
```

Run command to generate classes:
```bash
java -jar openapi-generator-cli.jar generate -i libretranslate.json -g php -o ~/projects/survos/test-libre-gen/
```

## Database

![Database Diagram](assets/db.svg)
