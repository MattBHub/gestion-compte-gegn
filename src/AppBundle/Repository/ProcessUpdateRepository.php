<?php

namespace AppBundle\Repository;

/**
 * ProcessUpdateRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ProcessUpdateRepository extends \Doctrine\ORM\EntityRepository
{
    public function findFrom(\DateTime $from, \DateTime $max = null)
    {
        $qb = $this->createQueryBuilder('p');

        $qb
            ->select('p')
            ->where('p.date > :from')
            ->setParameter('from', $from);
        if ($max) {
            $qb
                ->andWhere('p.date < :max')
                ->setParameter('max', $max);
        }

        $qb->orderBy('p.date', 'ASC');

        return $qb
            ->getQuery()
            ->getResult();
    }

    public function countFrom(\DateTime $from, \DateTime $max = null)
    {
        $qb = $this->createQueryBuilder('p');

        $qb
            ->select('count(p.id)')
            ->where('p.date > :from')
            ->setParameter('from', $from);
        if ($max) {
            $qb
                ->andWhere('p.date < :max')
                ->setParameter('max', $max);
        }

        return $qb
            ->getQuery()
            ->getSingleScalarResult();
    }
}
