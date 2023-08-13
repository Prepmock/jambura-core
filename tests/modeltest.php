<?php

use PHPUnit\Framework\TestCase;
use Jambura\Mvc\Model;
use Jambura\Mvc\JamburaValidationError;

class ModelTest extends TestCase
{
    public static function validateColumnDataProvider()
    {
        return [
            ['email', 'shahriar@gmail.com', null, null],
            ['email', 'shahriar@prepmock.com', null, null],
            ['email', 'shahriar123@prepmock.com', null, null],
            ['email', 'shahriar.hossain1@g.bracu.ac.bd', null, null],
            ['email', 'shahriargmail.com', JamburaValidationError::class, 'Invalid email format']
        ];
    }

    /**
     * @test
     * @dataProvider validateColumnDataProvider
     */
    public function testValidateColumn($column, $value, $expectedException = null, $expectedExceptionMessage = null)
    {
        $model = $this->getMockBuilder(Model::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validation'])
            ->getMock();

        $model->expects($this->any())
            ->method('validation')
            ->willReturn([
                'email' => ['regex', '/^.+@.+\..+$/', 'Invalid email format']
            ]);

        $modelReflection = new ReflectionClass($model);
        $validateColumn = $modelReflection->getMethod('validateColumn');
        $validateColumn->setAccessible(true);

        try {
            $validateColumn->invoke($model, $column, $value);

            if ($expectedException !== null) {
                $this->fail("Expected exception $expectedException was not thrown.");
            } else {
                $this->assertTrue(true, "No exception was thrown as expected.");
            }
        } catch (Exception $e) {
            if ($expectedException === null) {
                throw $e;
            }

            $this->assertInstanceOf($expectedException, $e, "Expected exception of type $expectedException, but got " . get_class($e));
            if ($expectedExceptionMessage !== null) {
                $this->assertEquals($expectedExceptionMessage, $e->getMessage(), "Exception message does not match expected.");
            }
        }
    }
}
