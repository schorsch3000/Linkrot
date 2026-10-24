// Command linkrot uploads files to a Linkrot server.
package main

import (
	"os"
	"path/filepath"

	"github.com/schorsch3000/Linkrot/internal/cli"
)

func main() {
	os.Exit(cli.Execute(filepath.Base(os.Args[0]), os.Args[1:], os.Stdout, os.Stderr))
}
