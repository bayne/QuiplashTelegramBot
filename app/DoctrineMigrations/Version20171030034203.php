<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20171030034203 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE answer DROP FOREIGN KEY FK_DADD4A2599E6F5DF');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C1FB8D185');
        $this->addSql('ALTER TABLE game_player DROP FOREIGN KEY FK_E52CD7AD99E6F5DF');
        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A10856499E6F5DF');
        $this->addSql('CREATE TABLE game_user (game_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_6686BA65E48FD905 (game_id), INDEX IDX_6686BA65A76ED395 (user_id), PRIMARY KEY(game_id, user_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT NOT NULL, is_bot TINYINT(1) NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE game_user ADD CONSTRAINT FK_6686BA65E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_user ADD CONSTRAINT FK_6686BA65A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE game_player');
        $this->addSql('DROP TABLE player');
        $this->addSql('DROP INDEX IDX_DADD4A2599E6F5DF ON answer');
        $this->addSql('DELETE FROM vote');
        $this->addSql('DELETE FROM answer');
        $this->addSql('ALTER TABLE answer CHANGE player_id user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A25A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_DADD4A25A76ED395 ON answer (user_id)');
        $this->addSql('DELETE FROM game');
        $this->addSql('ALTER TABLE game CHANGE gathering_players_started gathering_users_started DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C1FB8D185 FOREIGN KEY (host_id) REFERENCES user (id)');
        $this->addSql('DROP INDEX IDX_5A10856499E6F5DF ON vote');
        $this->addSql('ALTER TABLE vote CHANGE player_id user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_5A108564A76ED395 ON vote (user_id)');
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE answer DROP FOREIGN KEY FK_DADD4A25A76ED395');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C1FB8D185');
        $this->addSql('ALTER TABLE game_user DROP FOREIGN KEY FK_6686BA65A76ED395');
        $this->addSql('ALTER TABLE vote DROP FOREIGN KEY FK_5A108564A76ED395');
        $this->addSql('CREATE TABLE game_player (game_id INT NOT NULL, player_id INT NOT NULL, INDEX IDX_E52CD7ADE48FD905 (game_id), INDEX IDX_E52CD7AD99E6F5DF (player_id), PRIMARY KEY(game_id, player_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE player (id INT NOT NULL, name VARCHAR(255) NOT NULL COLLATE utf8_unicode_ci, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7AD99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_player ADD CONSTRAINT FK_E52CD7ADE48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE game_user');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP INDEX IDX_DADD4A25A76ED395 ON answer');
        $this->addSql('ALTER TABLE answer CHANGE user_id player_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A2599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('CREATE INDEX IDX_DADD4A2599E6F5DF ON answer (player_id)');
        $this->addSql('ALTER TABLE game CHANGE gathering_users_started gathering_players_started DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C1FB8D185 FOREIGN KEY (host_id) REFERENCES player (id)');
        $this->addSql('DROP INDEX IDX_5A108564A76ED395 ON vote');
        $this->addSql('ALTER TABLE vote CHANGE user_id player_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A10856499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('CREATE INDEX IDX_5A10856499E6F5DF ON vote (player_id)');
    }
}
