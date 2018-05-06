# Quiplash Telegram Bot

A bot for playing the [Quiplash mini-game from Jackbox](https://jackboxgames.com/project/quiplash/) in a group chat on 
Telegram. [@QuiplashModeratorBot](https://telegram.me/quiplashmoderatorbot)

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes.

### Prerequisites

What things you need to install the software and how to install them

```
brew install php71
```

Be sure to install [composer](https://getcomposer.org/download/)

### Installing

A step by step series of examples that tell you have to get a development env running

Run composer install

```
composer install
```

Create the development database

```
php bin/console doctrine:schema:create
```

Add the questions to the database

```
php bin/console app:load_questions
```

Test the app using the telegram emulator

```
php bin/console app:test:say joe "/new notimer"
```

## Built With

* [Symfony](http://symfony.com) - The web framework used
