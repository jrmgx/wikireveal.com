# Wikireveal

A multilingual version of redactle.com published at [wikireveal.com](https://wikireveal.com)

> Redactle is a daily browser game where the user tries to determine the subject of a random obfuscated article chosen from Wikipedia's 10,000 Vital Articles (Level 4).

This version is focused on user contributions & open source with a focus on implementing multiples languages by letting anyone contribute easily.

## Technical Stack

This app/website uses PHP and Symfony as well as some vanilla Javascript. It aims to be the simplest stack possible, so anyone could contribute.

### Local development

The project comes with a `Dockerfile`, so you can bootstrap your dev env in no time, the image is published at `jrmgx/wikireveal`

Run all the project commands with this line: `docker run -it --rm -v "$(pwd)":/app -p 8000:8000 jrmgx/wikireveal COMMAND`

You should make an alias like this: `alias wikireveal="docker run -it --rm -v "$(pwd)":/app -p 8000:8000 jrmgx/wikireveal"`. Now you can start any command easily like `wikireveal bin/console app:build` (this notation will be used in the doc from now).

### Local server

To ease the dev process, the project comes with a local server that shows the last daily puzzle. This allows you to take benefit of Symfony tooling.

First you must install the dependencies with: `wikireveal composer install` then start the local server: `wikireveal symfony server:start` and head out to http://localhost:8000

## Static Generator

The project is basically a static website generator, the command: `wikireveal bin/console app:build` will generate the daily puzzle ready to be published.

You can test the generated result via: `wikireveal php -S 0.0.0.0:8000 -t docs`. It will start a dumb PHP server where you can check for the result.

It uses Github action to compute the new puzzle every day.

## Limitations

The language interface will work for some languages, but not all. It is probably more suited for latin based language, but any contribution is welcome.

## Contributing

If you like, you can try to implement your own/favorite language and open a PR to get it integrated. Other type of contributions are welcome too, navigate to the issues tabs and pick your favorite!

### Implement Your Language

Adding a new language should be as easy as implementing the `App\Language\LanguageInterface`. This interface is heavily documented and if you need some example you can have a look at the `FrenchLanguage` implementation.

Please start a first draft, make a PR and let us talk about it to make it work together.

### Coding style

There is no framework on the CSS/Javascript side, so it is easy for anyone to pick it up.

The coding style is enforced by PHPCS and ESLint, you can simply launch those command before sending your PR:
`wikireveal composer phpcs-fix` and `wikireveal composer eslint-fix`
