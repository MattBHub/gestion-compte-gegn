<?php
// src/AppBundle/Command/ShiftGenerateCommand.php
namespace AppBundle\Command;

use AppBundle\Entity\Membership;
use AppBundle\Entity\Period;
use AppBundle\Entity\PeriodPosition;
use AppBundle\Entity\Shift;
use AppBundle\Entity\TimeLog;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShiftGenerateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:shift:generate')
            ->setDescription('Generate shift from period')
            ->setHelp('This command allows you to generate shift using period')
            ->addArgument('date', InputArgument::REQUIRED, 'The date format yyyy-mm-dd')
            ->addOption('to','t',InputOption::VALUE_OPTIONAL,'Every day until this date','')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $use_fly_and_fixed = $this->getContainer()->getParameter('use_fly_and_fixed');

        $from_given = $input->getArgument('date');
        $to_given = $input->getOption('to');
        $from = date_create_from_format('Y-m-d',$from_given);
        if (!$from || $from->format('Y-m-d') != $from_given){
            $output->writeln('<fg=red;> wrong date format. Use Y-m-d </>');
            return;
        }
        if ($to_given){
            $to = date_create_from_format('Y-m-d',$to_given);
            $output->writeln('<fg=yellow;>'.'Shift generation from <fg=cyan;>'.$from->format('d M Y').'</><fg=yellow;> to </><fg=cyan;>'.$to->format('d M Y').'</>');
        }else{
            $to = clone $from;
            $to->add(\DateInterval::createFromDateString('+1 Day'));
            $output->writeln('<fg=yellow;>'.'Shift generation for </><fg=cyan;>'.$from->format('d M Y').'</>');
        }
        $interval = \DateInterval::createFromDateString('1 day');
        $period = new \DatePeriod($from, $interval, $to);

        $count = 0;
        $count2 = 0;

        $reservedShifts = array();
        $formerShifts = array();

        $router = $this->getContainer()->get('router');

        $em = $this->getContainer()->get('doctrine')->getManager();
        $mailer = $this->getContainer()->get('mailer');
        $periodRepository = $em->getRepository('AppBundle:Period');
        $admin = $em->getRepository('AppBundle:User')->findSuperAdminAccount();

        $weekCycle = array("A", "B", "C", "D");
        foreach ( $period as $date ) {
            $output->writeln('<fg=cyan;>'.$date->format('d M Y').'</>');
            ////////////////////////
            $dayOfWeek = $date->format('N') - 1; //0 = 1-1 (for Monday) through 6=7-1 (for Sunday)

            $qb = $periodRepository
                ->createQueryBuilder('p');
            $qb->where('p.dayOfWeek = :dow')
                ->setParameter('dow', $dayOfWeek)
                ->orderBy('p.start');
            $periods = $qb->getQuery()->getResult();

            echo "\nCréation du : ". $from->format("d-m-Y H:i"). " au : ". $to->format("d-m-Y H:i");

            /** @var Period $period */
            foreach ($periods as $period) {

                $shift = new Shift();
                $start = date_create_from_format('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $period->getStart()->format('H:i'));
                $shift->setStart($start);
                $end = date_create_from_format('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $period->getEnd()->format('H:i'));
                $shift->setEnd($end);

                $lastStart = $this->lastCycleDate($start);
                $lastEnd = $this->lastCycleDate($end);

                echo "\n Copie du créneau du : ". $lastStart->format("d-m-Y") . " de " .$lastStart->format("H:i")." à : ". $lastEnd->format("H:i");

                /** @var PeriodPosition $position */
                foreach ($period->getPositions() as $position) {

                    echo "\n  Poste : ". $position->getFormation();

                    // Semaine #A-B-C-D
                    // Ignorer les periodes en dehors du cycle semaine
                    $weekCycleIndex = ($date->format('W') - 1) % 4; //0 = (1-1)%4 (first week) through 51
                    if ($weekCycle[$weekCycleIndex] != $position->getWeekCycle()) {
                        continue;
                    }

                    $already_generated = $em->getRepository('AppBundle:Shift')->findBy(array('start' => $start, 'end' => $end, 'job' => $period->getJob(), 'position' => $position));
                    if (!$already_generated) {
                        $last_cycle_shift = $em->getRepository('AppBundle:Shift')->findOneBy(array('start' => $lastStart, 'end' => $lastEnd, 'job' => $period->getJob(), 'position' => $position));
                        $current_shift = clone $shift;
                        $current_shift->setJob($period->getJob());
                        $current_shift->setFormation($position->getFormation());
                        $current_shift->setPosition($position);
                        $current_shift->setShifter(null);
                        $current_shift->setBookedTime(null);
                        $current_shift->setBooker(null);

                        // si c'est un créneau fixe (parametré par les gestionnaires)
                        // il est automatiquement assigné au shifter et rien ne lui est demandé
                        if ($use_fly_and_fixed
                            && $position->getShifter() != null &&
                            !$position->getShifter()->getMembership()->isExemptedFromShifts($current_shift->getStart())) {
                            $current_shift->setFixe(True);
                            $current_shift->setShifter($position->getShifter());
                            $current_shift->setBookedTime(new \DateTime('now'));
                            $current_shift->setBooker($admin);
                        }
                        // on attribut le shift directement
                        else if (
                            $last_cycle_shift &&
                            $last_cycle_shift->isFixe() &&
                            $last_cycle_shift->getShifter() &&
                            $this->getContainer()->getParameter('reserve_new_shift_to_prior_shifter')) {
                            $current_shift->setShifter($last_cycle_shift->getShifter());
                            $current_shift->setBookedTime(new \DateTime('now'));
                            $current_shift->setFixe(true);
                            $reservedShifts[$count] = $current_shift;
                            $formerShifts[$count] = $last_cycle_shift;
                        }

                        $em->persist($current_shift);

                        // puisqu'il est assigné, il faut mettre le log tout de suite
                        if ($current_shift->isFixe()){
                            $this->createShiftLog($em, $current_shift, $current_shift->getShifter()->getMembership());
                        }

                        $count++;
                    } else {
                        $count2++;
                    }
                }
            }
            $em->flush();
        }
        $shiftEmail = $this->getContainer()->getParameter('emails.shift');
        foreach ($reservedShifts as $i => $shift){
            $d = (date_diff(new \DateTime('now'),$shift->getStart())->format("%d"));
            $mail = (new \Swift_Message('[ESPACE MEMBRES] Reprise automatique de ton créneau du '. $formerShifts[$i]->getStart()->format("d/m") .' dans '.$d.' jours'))
                ->setFrom($shiftEmail['address'], $shiftEmail['from_name'])
                ->setTo($shift->getShifter()->getEmail())
                ->setBody(
                    $this->getContainer()->get('twig')->render(
                        'emails/shift_reserved.html.twig',
                        array(
                            'shift' => $shift,
                            'oldshift' => $formerShifts[$i],
                            'days' => $d,
//                            'accept_url' => $router->generate('shift_accept_reserved', array('id' => $shift->getId(),'token'=> $shift->getTmpToken($shift->getshifter()->getId())),UrlGeneratorInterface::ABSOLUTE_URL),
//                            'reject_url' => $router->generate('shift_reject_reserved', array('id' => $shift->getId(),'token'=> $shift->getTmpToken($shift->getshifter()->getId())),UrlGeneratorInterface::ABSOLUTE_URL),
                        )
                    ),
                    'text/html'
                );
            $mailer->send($mail);
        }

        $message = $count.' créneau'.(($count>1) ? 'x':'').' généré'.(($count>1) ? 's':'');
        $output->writeln('<fg=cyan;>>>></><fg=green;> '.$message.' </>');
        $message = $count2.' créneau'.(($count2>1) ? 'x':'').' existe'.(($count2>1) ? 'nt':'');
        $output->writeln('<fg=cyan;>>>></><fg=red;> '.$message.' déjà </>');
    }

    protected function lastCycleDate(\DateTime $date)
    {
        $lastCycleDate = clone($date);
        $lastCycleDate->modify("-28 days");
        return $lastCycleDate;
    }

    private function createShiftLog(EntityManager $em, Shift $shift, Membership $membership) {
        $log = new TimeLog();
        $log->setMembership($membership);
        $log->setTime($shift->getDuration());
        $log->setShift($shift);
        $log->setCreatedAt($shift->getStart());
        $log->setType(1);
        $em->persist($log);
    }
}
