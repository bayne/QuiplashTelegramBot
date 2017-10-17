<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20171017053841 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE answer (id INT AUTO_INCREMENT NOT NULL, player_id INT DEFAULT NULL, question_id INT DEFAULT NULL, game_id INT DEFAULT NULL, response LONGTEXT NOT NULL, INDEX IDX_DADD4A2599E6F5DF (player_id), INDEX IDX_DADD4A251E27F6BF (question_id), INDEX IDX_DADD4A25E48FD905 (game_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE game (id INT AUTO_INCREMENT NOT NULL, host_id INT DEFAULT NULL, current_question_id INT DEFAULT NULL, chatGroup VARCHAR(255) NOT NULL, state VARCHAR(255) NOT NULL, INDEX IDX_232B318C1FB8D185 (host_id), UNIQUE INDEX UNIQ_232B318CA0F35D66 (current_question_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE game_player (game_id INT NOT NULL, player_id INT NOT NULL, INDEX IDX_E52CD7ADE48FD905 (game_id), INDEX IDX_E52CD7AD99E6F5DF (player_id), PRIMARY KEY(game_id, player_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE game_question (game_id INT NOT NULL, question_id INT NOT NULL, INDEX IDX_1DB3B668E48FD905 (game_id), INDEX IDX_1DB3B6681E27F6BF (question_id), PRIMARY KEY(game_id, question_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE player (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE question (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vote (id INT AUTO_INCREMENT NOT NULL, answer_id INT DEFAULT NULL, question_id INT DEFAULT NULL, player_id INT DEFAULT NULL, game_id INT DEFAULT NULL, INDEX IDX_5A108564AA334807 (answer_id), INDEX IDX_5A1085641E27F6BF (question_id), INDEX IDX_5A10856499E6F5DF (player_id), INDEX IDX_5A108564E48FD905 (game_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A2599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A251E27F6BF FOREIGN KEY (question_id) REFERENCES question (id)');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A25E48FD905 FOREIGN KEY (game_id) REFERENCES game (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C1FB8D185 FOREIGN KEY (host_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CA0F35D66 FOREIGN KEY (current_question_id) REFERENCES question (id)');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7ADE48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7AD99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_question ADD CONSTRAINT FK_1DB3B668E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_question ADD CONSTRAINT FK_1DB3B6681E27F6BF FOREIGN KEY (question_id) REFERENCES question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564AA334807 FOREIGN KEY (answer_id) REFERENCES answer (id)');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A1085641E27F6BF FOREIGN KEY (question_id) REFERENCES question (id)');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A10856499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564E48FD905 FOREIGN KEY (game_id) REFERENCES game (id)');
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A108564AA334807');
        $this->addSql('ALTER TABLE answer DROP FOREIGN KEY FK_DADD4A25E48FD905');
        $this->addSql('ALTER TABLE game_player DROP FOREIGN KEY FK_E52CD7ADE48FD905');
        $this->addSql('ALTER TABLE game_question DROP FOREIGN KEY FK_1DB3B668E48FD905');
        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A108564E48FD905');
        $this->addSql('ALTER TABLE answer DROP FOREIGN KEY FK_DADD4A2599E6F5DF');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C1FB8D185');
        $this->addSql('ALTER TABLE game_player DROP FOREIGN KEY FK_E52CD7AD99E6F5DF');
        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A10856499E6F5DF');
        $this->addSql('ALTER TABLE answer DROP FOREIGN KEY FK_DADD4A251E27F6BF');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CA0F35D66');
        $this->addSql('ALTER TABLE game_question DROP FOREIGN KEY FK_1DB3B6681E27F6BF');
        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A1085641E27F6BF');
        $this->addSql('DROP TABLE answer');
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE game_player');
        $this->addSql('DROP TABLE game_question');
        $this->addSql('DROP TABLE player');
        $this->addSql('DROP TABLE question');
        $this->addSql('DROP TABLE vote');
    }
}
