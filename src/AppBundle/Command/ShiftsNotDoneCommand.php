<?php

namespace AppBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShiftsNotDoneCommand extends ContainerAwareCommand {

    protected function configure() {
        $this
            ->setName('app:user:shifts_not_done')
            ->setDescription('Get Shifts Not Done')
            ->setHelp('no help, sorry');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {

        /** @var EntityManagerInterface $em */
        $em = $this->getContainer()->get('doctrine')->getManager();

        $conn = $em->getConnection();
        $sql = "SELECT t.membership_id, t.date, t.type, t.time,  UPPER(b.lastname) as nom, LOWER(b.firstname) AS prenom, b.id 
            -- , b.lastname, b. firstname
            FROM time_log t 
            JOIN membership m  ON t.membership_id = m.id
            JOIN beneficiary b ON m.main_beneficiary_id = b.id
            WHERE t.type <=2 
            -- AND m.member_number = xx
            ORDER BY t.membership_id, date;";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $members = $stmt->fetchAll(\PDO::FETCH_GROUP);
//        var_dump($members);

        $toPrint[] = ['nom', 'prenom', 'dateDebut', 'dateFin', 'id'];

        $months=[];
        foreach ($members as $member) {
            $cycleStart = null;
            $shiftHasBeenDone = true;

            foreach ($member as $log) {
                if ($log['type'] == 1 || ($log['type'] == 0 && $log['time'] >= 120) ) {
                    $shiftHasBeenDone = true;
                    continue;
                }

                if ($shiftHasBeenDone == false) {

                    $toPrint[] = [$log['nom'], $log['prenom'], self::dateToDisplay($cycleStart), self::dateToDisplay($log['date']), $log['id']];
//                    $output->writeln("not done between " . $cycleStart . " and " . $log['date']);

                    $mois = self::dateToMonth($cycleStart);
                    // $months[$mois][] = $log['nom'] . ' '. $log['prenom'];

                    if (key_exists($mois, $months)) {
                        $nombre = $months[$mois];
                        $months[$mois] = $nombre+1;
                    } else {
                        $months[$mois] = 1;
                    }
                }
                $shiftHasBeenDone = false;
                $cycleStart = $log['date'];
            }
        }

//        // fichier excel
//        $fp = fopen('shifts_not_done.csv', 'w');
//        //fputs($fp, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
//        foreach ($toPrint as $lines) {
//            fputcsv($fp, $lines);
//        }
//        fclose($fp);

    }

    protected function dateToDisplay($value) {
        $parsed = substr($value, 0, 10);
        $annee = substr($parsed,0,4);
        $mois = substr($parsed,5,2);
        $jour = substr($parsed,8,2);
        $date = $jour .'/'.$mois.'/'. $annee;
        return $date;
    }

    protected function dateToMonth($value){
        $parsed = substr($value, 0, 10);
        $annee = substr($parsed,0,4);
        $mois = substr($parsed,5,2);
        $date = $mois.'/'. $annee;
        return $date;
    }

}