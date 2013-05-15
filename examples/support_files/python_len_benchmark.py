from sys import path
from os.path import dirname, abspath

ampy_dir = dirname(dirname(dirname(abspath(__file__)))) + '/workers/python'
path.append(ampy_dir)
import ampy

if __name__ == "__main__":

    ampy.listen({'len': len})
    
