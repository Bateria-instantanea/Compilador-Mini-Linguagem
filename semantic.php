<?php
// ============================================================
// SEMANTIC.PHP — Van Language v2.0
// Verificações: tipos, escopo local/global, vars não declaradas
// divisão por zero, variáveis não utilizadas, funções
// ============================================================

require_once __DIR__ . '/parser.php';

class MiniSemanticError extends Exception {
    public int $linhaCodigo;
    public function __construct(string $msg, int $linha = 0) {
        parent::__construct($msg);
        $this->linhaCodigo = $linha;
    }
}

// ============================================================
// Tabela de Símbolos com escopo
// ============================================================
class TabelaDeSimbolos {

    private array $global    = []; // nome => [linha, tipo, valor]
    private array $local     = [];
    private bool  $emFuncao  = false;
    private array $utilizadas = [];

    public function entrarFuncao(): void { $this->emFuncao = true; $this->local = []; }
    public function sairFuncao(): void   { $this->emFuncao = false; $this->local = []; }

    public function declarar(string $nome, string $tipo, int $linha, mixed $valor = null): void {
        $entrada = ['linha'=>$linha,'tipo'=>$tipo,'valor'=>$valor];
        if ($this->emFuncao) $this->local[$nome]  = $entrada;
        else                 $this->global[$nome] = $entrada;
    }

    public function usar(string $nome, int $linha): string {
        // Procura local primeiro, depois global
        if ($this->emFuncao && isset($this->local[$nome])) {
            $this->utilizadas[$nome] = $linha;
            return $this->local[$nome]['tipo'];
        }
        if (isset($this->global[$nome])) {
            $this->utilizadas[$nome] = $linha;
            return $this->global[$nome]['tipo'];
        }
        throw new MiniSemanticError("Variável '$nome' usada antes de ser declarada", $linha);
    }

    public function getValor(string $nome): mixed {
        if ($this->emFuncao && isset($this->local[$nome])) return $this->local[$nome]['valor'];
        return $this->global[$nome]['valor'] ?? null;
    }

    public function getTipo(string $nome): ?string {
        if ($this->emFuncao && isset($this->local[$nome])) return $this->local[$nome]['tipo'];
        return $this->global[$nome]['tipo'] ?? null;
    }

    public function getNaoUtilizadas(): array {
        $res = [];
        foreach ($this->global as $nome => $info) {
            if (!isset($this->utilizadas[$nome]))
                $res[] = ['nome'=>$nome,'linha'=>$info['linha']];
        }
        return $res;
    }

    public function toArray(): array {
        $tabela = [];
        foreach ($this->global as $n => $i) {
            $tabela[] = ['nome'=>$n,'escopo'=>'global','tipo'=>$i['tipo'],
                'linha_decl'=>$i['linha'],'linha_uso'=>$this->utilizadas[$n]??null,
                'valor'=>$i['valor']??'?','usada'=>isset($this->utilizadas[$n])];
        }
        foreach ($this->local as $n => $i) {
            $tabela[] = ['nome'=>$n,'escopo'=>'local','tipo'=>$i['tipo'],
                'linha_decl'=>$i['linha'],'linha_uso'=>$this->utilizadas[$n]??null,
                'valor'=>$i['valor']??'?','usada'=>isset($this->utilizadas[$n])];
        }
        return $tabela;
    }
}

// ============================================================
class AnalisadorSemantico {

    private TabelaDeSimbolos $tabela;
    private array $erros   = [];
    private array $avisos  = [];
    private array $funcoes = [];

    public function __construct() { $this->tabela = new TabelaDeSimbolos(); }

    public function analisar(array $ast): array {
        $this->erros   = [];
        $this->avisos  = [];
        $this->tabela  = new TabelaDeSimbolos();
        $this->funcoes = [];

        // 1ª passagem: registra todas as funções declaradas
        foreach ($ast['corpo'] as $stmt) {
            if ($stmt['no'] === 'funcdef') {
                if (isset($this->funcoes[$stmt['nome']]))
                    $this->erros[] = ['msg'=>"Função '{$stmt['nome']}' declarada duas vezes",'linha'=>$stmt['linha']];
                $this->funcoes[$stmt['nome']] = $stmt;
            }
        }

        // 2ª passagem: analisa tudo
        $this->visitarBloco($ast['corpo']);

        foreach ($this->tabela->getNaoUtilizadas() as $nu) {
            $this->avisos[] = ['msg'=>"Variável '{$nu['nome']}' declarada mas nunca utilizada",'linha'=>$nu['linha']];
        }

        return [
            'erros'   => $this->erros,
            'avisos'  => $this->avisos,
            'tabela'  => $this->tabela->toArray(),
            'funcoes' => array_keys($this->funcoes),
            'valido'  => empty($this->erros),
        ];
    }

    private function visitarBloco(array $stmts): void {
        foreach ($stmts as $s) $this->visitarStmt($s);
    }

    private function visitarStmt(array $no): void {
        switch ($no['no']) {

            case 'atrib':
                $v = $this->avaliarExpr($no['expr']);
                $this->tabela->declarar($no['var']->valor, 'int', $no['linha'], $v);
                break;

            case 'fatrib':
                $v = $this->avaliarExpr($no['expr']);
                $this->tabela->declarar($no['var']->valor, 'float', $no['linha'], $v);
                break;

            case 'satrib':
                $this->verificarTemplate($no['partes'], $no['linha']);
                $this->tabela->declarar($no['var']->valor, 'string', $no['linha'], null);
                break;

            case 'input':
                $t    = $no['var'];
                $tipo = match($t->tipo) { 'FVAR'=>'float','SVAR'=>'string',default=>'int' };
                $this->tabela->declarar($t->valor, $tipo, $no['linha'], null);
                break;

            case 'print':
                try { $this->tabela->usar($no['var']->valor, $no['linha']); }
                catch (MiniSemanticError $e) { $this->erros[] = ['msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
                break;

            case 'print_tmpl':
                $this->verificarTemplate($no['partes'], $no['linha']);
                break;

            case 'if':
                $this->verificarCond($no['cond']);
                $this->visitarBloco($no['entao']);
                $this->visitarBloco($no['senao']);
                break;

            case 'while':
                $this->verificarCond($no['cond']);
                $this->visitarBloco($no['corpo']);
                break;

            case 'for':
                $this->visitarStmt($no['init']);
                $this->verificarCond($no['cond']);
                $this->visitarStmt($no['step']);
                $this->visitarBloco($no['corpo']);
                break;

            case 'funcdef':
                $this->tabela->entrarFuncao();
                $this->visitarBloco($no['corpo']);
                $this->tabela->sairFuncao();
                break;

            case 'funccall':
                if (!isset($this->funcoes[$no['nome']]))
                    $this->erros[] = ['msg'=>"Função '{$no['nome']}' chamada mas não declarada",'linha'=>$no['linha']];
                break;

            case 'return':
                if ($no['expr']) $this->avaliarExpr($no['expr']);
                break;
        }
    }

    private function verificarTemplate(array $partes, int $linha): void {
        foreach ($partes as $p) {
            if ($p['no'] === 'strlit') continue;
            try { $this->tabela->usar($p['nome'], $linha); }
            catch (MiniSemanticError $e) { $this->erros[] = ['msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo]; }
        }
    }

    private function avaliarExpr(array $no): mixed {
        return match($no['no']) {
            'num'   => $no['valor'],
            'fnum'  => $no['valor'],
            'var','fvar' => $this->usarVar($no['nome'], $no['linha']),
            'binop' => $this->avaliarBinop($no),
            default => null,
        };
    }

    private function usarVar(string $nome, int $linha): mixed {
        try {
            $this->tabela->usar($nome, $linha);
            return $this->tabela->getValor($nome);
        } catch (MiniSemanticError $e) {
            $this->erros[] = ['msg'=>$e->getMessage(),'linha'=>$e->linhaCodigo];
            return null;
        }
    }

    private function avaliarBinop(array $no): mixed {
        $e = $this->avaliarExpr($no['esq']);
        $d = $this->avaliarExpr($no['dir']);
        if ($no['op'] === '/' && $d === 0) {
            $this->erros[] = ['msg'=>'Divisão por zero detectada','linha'=>$no['linha']];
            return null;
        }
        if ($e !== null && $d !== null) {
            return match($no['op']) {
                '+'=>$e+$d, '-'=>$e-$d, '*'=>$e*$d,
                '/'=>$d!=0?$e/$d:null,
                default=>null,
            };
        }
        return null;
    }

    private function verificarCond(array $no): void {
        if ($no['no'] === 'cmp') {
            // Só gera aviso se AMBOS os lados forem literais numéricos (não variáveis)
            // Variáveis mudam dentro do bloco, então não é possível saber se é sempre V/F
            $esqEhLiteral = in_array($no['esq']['no'], ['num','fnum']);
            $dirEhLiteral = in_array($no['dir']['no'], ['num','fnum']);
            if (!$esqEhLiteral || !$dirEhLiteral) {
                // Pelo menos um lado é variável — apenas verifica declaração
                $this->avaliarExpr($no['esq']);
                $this->avaliarExpr($no['dir']);
                return;
            }
            $e = $this->avaliarExpr($no['esq']);
            $d = $this->avaliarExpr($no['dir']);
            if ($e !== null && $d !== null) {
                $res = match($no['op']) {
                    '=='=>$e==$d,'!='=>$e!=$d,'<'=>$e<$d,'>'=>$e>$d,'<='=>$e<=$d,'>='=>$e>=$d,
                    default=>null,
                };
                if ($res !== null) {
                    $txt = $res ? 'sempre verdadeira' : 'sempre falsa';
                    $this->avisos[] = ['msg'=>"Condição com literais é $txt ($e {$no['op']} $d)",'linha'=>$no['linha']??0];
                }
            }
        } elseif ($no['no'] === 'log') {
            $this->verificarCond($no['esq']);
            $this->verificarCond($no['dir']);
        }
    }

    public function getTabela(): TabelaDeSimbolos { return $this->tabela; }
}
