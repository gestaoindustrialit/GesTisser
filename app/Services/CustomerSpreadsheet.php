<?php
declare(strict_types=1);

final class CustomerSpreadsheet
{
    public static function columns(): array
    {
        return [
            'codigo'=>'code', 'nome'=>'name', 'nif'=>'tax_number', 'pais'=>'country',
            'codigo_cliente'=>'code', 'cod_cliente'=>'code', 'cliente'=>'name',
            'nome_cliente'=>'name', 'nome_fiscal'=>'name', 'razao_social'=>'name',
            'prefixo_pais'=>'country_prefix', 'telefone'=>'phone', 'telemovel'=>'mobile',
            'email'=>'email', 'morada_1'=>'address', 'morada_2'=>'address_2', 'cidade'=>'city',
            'codigo_postal'=>'postal_code', 'fax'=>'fax', 'contacto'=>'contact_name',
            'vendedor'=>'salesperson', 'desconto_percentagem'=>'discount_percent',
            'observacoes'=>'notes', 'saldo'=>'balance', 'plafond'=>'credit_limit', 'ativo'=>'is_active'
        ];
    }

    public static function read(string $path, string $extension): array
    {
        require_once __DIR__.'/ArticleSpreadsheet.php';
        return ArticleSpreadsheet::readWithColumns(
            $path,
            $extension,
            self::columns(),
            ['codigo', 'nome']
        );
    }
}
