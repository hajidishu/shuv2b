<?php

namespace Tests\P1;

use App\Services\DatabaseUpgrade;
use Illuminate\Support\Facades\DB;

class DatabaseUpgradeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::getSchemaBuilder()->dropIfExists('upgrade_fixture');
    }

    protected function tearDown(): void
    {
        DB::getSchemaBuilder()->dropIfExists('upgrade_fixture');
        parent::tearDown();
    }

    public function testSqlSplittingPreservesQuotedSemicolonsCommentsAndDecimalCommas()
    {
        $sql = "-- heading\nCREATE TABLE `fixture` (`id` int, `price` decimal(5,2), `title` varchar(80) DEFAULT 'a;b,c'); /* ; */ SELECT 'it''s;ok';";
        $parts = DatabaseUpgrade::statements($sql);
        $this->assertCount(2, $parts);
        $this->assertStringContainsString("'a;b,c'", $parts[0]);
        $this->assertCount(3, DatabaseUpgrade::statements("`id` int, `price` decimal(5,2), `title` varchar(80) DEFAULT 'a,b'", ','));
    }

    public function testIncompleteSqlFailsRatherThanPartiallyParsing()
    {
        $this->expectException(\RuntimeException::class);
        DatabaseUpgrade::statements("SELECT 'unfinished;");
    }

    public function testResumeDoesNotDropExistingTableOrItsData()
    {
        DB::statement('CREATE TABLE upgrade_fixture (id int, name varchar(20))');
        DB::table('upgrade_fixture')->insert(['id' => 7, 'name' => 'retained']);
        $upgrade = new DatabaseUpgrade();
        $upgrade->run('DROP TABLE IF EXISTS `upgrade_fixture`; CREATE TABLE `upgrade_fixture` (`id` int, `name` varchar(20));', false);
        $this->assertSame('retained', DB::table('upgrade_fixture')->where('id', 7)->value('name'));
        $upgrade->verify('CREATE TABLE `upgrade_fixture` (`id` int, `name` varchar(20))');
    }

    public function testUpdateSplitsAlterClausesSoOneExistingFieldDoesNotHideAnother()
    {
        DB::statement('CREATE TABLE upgrade_fixture (id int, old_field int)');
        $upgrade = new DatabaseUpgrade();
        $upgrade->run('ALTER TABLE `upgrade_fixture` ADD `old_field` int, ADD `new_field` int AFTER `removed_anchor`;', true);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('upgrade_fixture', 'new_field'));
    }

    public function testUnknownColumnOrBrokenSqlStopsTheUpdate()
    {
        $this->expectException(\RuntimeException::class);
        (new DatabaseUpgrade())->run('UPDATE `v2_user` SET `nonexistent_field` = 1;', true);
    }

    public function testFinalSchemaValidationDetectsMissingRequiredColumns()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('v2_user.required_new_field');
        (new DatabaseUpgrade())->verify('CREATE TABLE `v2_user` (`id` int, `required_new_field` text)');
    }

    public function testInstallFailureReturnsNonZeroInsteadOfReportingSuccess()
    {
        if (!class_exists('File', false)) class_alias(\Illuminate\Support\Facades\File::class, 'File');
        app()->instance('files', new \Illuminate\Filesystem\Filesystem());
        \Illuminate\Support\Facades\File::shouldReceive('exists')->once()->andThrow(new \RuntimeException('fixture install failure'));
        $command = new class extends \App\Console\Commands\V2boardInstall {
            public $messages = [];
            public function info($string, $verbosity = null) {}
            public function error($string, $verbosity = null) { $this->messages[] = $string; }
        };
        $this->assertSame(1, $command->handle());
        $this->assertSame(['fixture install failure'], $command->messages);
    }
}
