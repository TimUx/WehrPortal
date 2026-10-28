<?php

namespace Modules\Gefahrstoffe\src\Data;

class GhsPictograms
{
    /**
     * @return list<array{code: string, label: string, description: string}>
     */
    public static function all(): array
    {
        return [
            ['code' => 'GHS01', 'label' => 'Explodierende Bombe', 'description' => 'Explosive Stoffe und Gemische sowie bestimmte selbstzersetzliche Stoffe und organische Peroxide.'],
            ['code' => 'GHS02', 'label' => 'Flamme', 'description' => 'Entzündbare Gase, Aerosole, Flüssigkeiten und Feststoffe; auch selbstzersetzliche und pyrophore Stoffe.'],
            ['code' => 'GHS03', 'label' => 'Flamme über einem Kreis', 'description' => 'Oxidierende Gase, Flüssigkeiten und Feststoffe, die Brände verursachen oder verstärken können.'],
            ['code' => 'GHS04', 'label' => 'Gasflasche', 'description' => 'Gase unter Druck, einschließlich verdichteter, verflüssigter und tiefgekühlt verflüssigter Gase.'],
            ['code' => 'GHS05', 'label' => 'Ätzwirkung', 'description' => 'Kann Metalle angreifen, schwere Hautverätzungen oder schwere Augenschäden verursachen.'],
            ['code' => 'GHS06', 'label' => 'Totenkopf mit gekreuzten Knochen', 'description' => 'Akute Toxizität: kann bereits in kleinen Mengen giftig oder tödlich sein.'],
            ['code' => 'GHS07', 'label' => 'Ausrufezeichen', 'description' => 'Kann gesundheitsschädlich oder reizend wirken, Allergien der Haut auslösen oder Benommenheit verursachen.'],
            ['code' => 'GHS08', 'label' => 'Gesundheitsgefahr', 'description' => 'Kann schwere oder chronische Gesundheitsschäden verursachen, etwa Krebs oder Organschäden.'],
            ['code' => 'GHS09', 'label' => 'Umwelt', 'description' => 'Ist für Wasserorganismen giftig und kann langfristige Schäden in Gewässern verursachen.'],
        ];
    }
}
