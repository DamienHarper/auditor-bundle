<?php

namespace DH\DoctrineAuditBundle\Tests;

trait ArrayTestCaseTrait
{
    /**
     * Asserts that two associative arrays are similar.
     *
     * Both arrays must have the same indexes with identical values
     * without respect to key ordering
     *
     * @param array $expected
     * @param array $array
     */
    protected function assertArraySimilar(array $expected, array $array): void
    {
        $this->assertCount(0, array_diff_key($array, $expected));

        foreach ($expected as $key => $value) {
            if (\is_array($value)) {
                $this->assertArraySimilar($value, $array[$key]);
            } else {
                $this->assertContains($value, $array);
            }
        }
    }
}
