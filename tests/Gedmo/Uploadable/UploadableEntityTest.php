<?php

namespace Gedmo\Uploadable;

use Tool\BaseTestCaseORM;
use Doctrine\Common\EventManager;
use Doctrine\Common\Util\Debug,
    Uploadable\Fixture\Entity\Image,
    Uploadable\Fixture\Entity\Article,
    Uploadable\Fixture\Entity\File,
    Gedmo\Uploadable\Stub\UploadableListenerStub;

/**
 * These are tests for Uploadable behavior
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package Gedmo.Uploadable
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class UploadableEntityTest extends BaseTestCaseORM
{
    const IMAGE_CLASS = 'Uploadable\Fixture\Entity\Image';
    const ARTICLE_CLASS = 'Uploadable\Fixture\Entity\Article';
    const FILE_CLASS = 'Uploadable\Fixture\Entity\File';

    private $listener;
    private $testFile;
    private $testFile2;
    private $destinationTestDir;
    private $destinationTestFile;
    private $destinationTestFile2;
    private $testFilename;
    private $testFilename2;
    private $testFileSize;
    private $testFileMimeType;

    protected function setUp()
    {
        parent::setUp();

        $evm = new EventManager;
        $this->listener = new UploadableListenerStub();
        $evm->addEventSubscriber($this->listener);
        $config = $this->getMockAnnotatedConfig();
        $this->em = $this->getMockSqliteEntityManager($evm, $config);
        $this->testFile = __DIR__.'/../../data/test.txt';
        $this->testFile2 = __DIR__.'/../../data/test2.txt';
        $this->destinationTestDir = __DIR__.'/../../temp/uploadable';
        $this->destinationTestFile = $this->destinationTestDir.'/test.txt';
        $this->destinationTestFile2 = $this->destinationTestDir.'/test2.txt';
        $this->testFilename = substr($this->testFile, strrpos($this->testFile, '/') + 1);
        $this->testFilename2 = substr($this->testFile2, strrpos($this->testFile2, '/') + 1);
        $this->testFileSize = 4;
        $this->testFileMimeType = 'text/plain';

        mkdir($this->destinationTestDir);
    }

    protected function tearDown()
    {
        parent::tearDown();

        if (is_file($this->destinationTestFile)) {
            unlink($this->destinationTestFile);
        }

        if (is_file($this->destinationTestFile2)) {
            unlink($this->destinationTestFile2);
        }

        rmdir($this->destinationTestDir);
    }

    public function testUploadableEntity()
    {
        // INSERTION of an Uploadable Entity

        // If there was no uploaded file, we do nothing
        $image = new Image();
        $image->setTitle('123');

        $this->em->persist($image);
        $this->em->flush();

        $this->assertNull($image->getFilePath());

        // If there is an uploaded file, we process it
        $fileInfo = $this->generateUploadedFile();
        $image2 = new Image();
        $image2->setTitle('456');

        $this->em->persist($image2);
        $this->em->flush();

        $this->em->refresh($image2);

        $firstFile = $image2->getFilePath();

        $this->assertEquals($image2->getPath().DIRECTORY_SEPARATOR.$fileInfo['name'], $image2->getFilePath());
        $this->assertTrue(is_file($firstFile));

        // UPDATE of an Uploadable Entity

        // First we need to add the ID to the index of the element in $_FILES so it can be recognized
        // by the listener as the update info for the file $image2.
        unset($_FILES[$fileInfo['index']]);

        // We change the "uploaded" file
        $fileInfo['tmp_name'] = $this->testFile2;
        $fileInfo['name'] = $this->testFilename2;

        $_FILES[$fileInfo['index']] = array(
            $image2->getId()    => $fileInfo
        );

        // For now, we need to force the update changing one of the managed fields. If we don't do this,
        // entity won't be marked for update
        $image2->setTitle($image2->getTitle().'789');

        $this->em->flush();

        $this->em->refresh($image2);
        $lastFile = $image2->getFilePath();

        $this->assertEquals($image2->getPath().DIRECTORY_SEPARATOR.$fileInfo['name'], $image2->getFilePath());
        $this->assertTrue(is_file($lastFile));

        // First file should be removed on update
        $this->assertFalse(is_file($firstFile));

        // REMOVAL of an Uploadable Entity
        $this->em->remove($image2);
        $this->em->flush();

        $this->assertFalse(is_file($lastFile));
    }

    public function testEntityWithUploadableEntities()
    {
        $artRepo = $this->em->getRepository(self::ARTICLE_CLASS);
        $article = new Article();
        $article->setTitle('Test');

        $file1 = new File();
        $file2 = new File();
        $file3 = new File();

        $article->addFile($file1);
        $article->addFile($file2);
        $article->addFile($file3);

        $filesArrayIndex = strtr($file1->getFilesArrayIndex(), array('[' => '', ']' => ''));

        $fileInfo = $this->generateUploadedFile($filesArrayIndex);
        $fileInfo2 = $this->generateUploadedFile($filesArrayIndex);
        $fileInfo3 = $this->generateUploadedFile($filesArrayIndex);

        $_FILES[$fileInfo['index']] = array($fileInfo, $fileInfo2, $fileInfo3);

        $this->em->persist($article);

        $this->em->flush();

        $art = $artRepo->findOneByTitle('Test');
        $files = $art->getFiles();
        $file1Path = $file1->getPath().DIRECTORY_SEPARATOR.$fileInfo['name'];
        $file2Path = $file2->getPath().DIRECTORY_SEPARATOR.$fileInfo['name'];
        $file3Path = $file3->getPath().DIRECTORY_SEPARATOR.$fileInfo['name'];

        $this->assertEquals($file1Path, $files[0]->getFilePath());
        $this->assertEquals($file2Path, $files[1]->getFilePath());
        $this->assertEquals($file3Path, $files[2]->getFilePath());
    }

    public function testGetItemFromArrayMethod()
    {
        $dataText = 'My Data';
        $data = array('first' => array('second' => array('third' => $dataText)));
        $indexes = array('first', 'second', 'third');

        $result = $this->listener->getItemFromArray($data, $indexes);

        $this->assertEquals($dataText, $result);

        $data = array('first' => $dataText);

        $this->assertFalse($this->listener->getItemFromArray($data, $indexes));

        $data = array();

        $this->assertFalse($this->listener->getItemFromArray($data, $indexes));

        $data = array('first' => array('second' => array('anotherIndex' => $dataText)));
        $indexes = array('first', 'second', 'third');

        $this->assertFalse($this->listener->getItemFromArray($data, $indexes));

        $this->setExpectedException('InvalidArgumentException');

        $data = array('first' => $dataText);
        $indexes = array();

        $this->listener->getItemFromArray($data, $indexes);
    }

    private function generateUploadedFile($index = 'image', $file = false, array $info = array())
    {
        if (empty($info)) {
            $info = array(
                'tmp_name'          => !$file ? $this->testFile : $file,
                'name'              => $this->testFilename,
                'size'              => $this->testFileSize,
                'type'              => $this->testFileMimeType,
                'error'             => 0,
                'index'             => $index
            );
        }
        
        $_FILES[$index] = $info;

        return $info;
    }

    protected function getUsedEntityFixtures()
    {
        return array(
            self::IMAGE_CLASS,
            self::ARTICLE_CLASS,
            self::FILE_CLASS
        );
    }

    private function populate()
    {
    }
}