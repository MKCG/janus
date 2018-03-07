<?php

namespace MKCG\Janus\Example;

interface Calculus
{

}

interface Trigonometry
{

}

class Mathematica extends \MKCG\Janus\Example\Science implements Calculus, \MKCG\Janus\Example\Trigonometry
{

}

abstract final class Science
{

}
